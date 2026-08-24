# Setting up a Hetzner Object Storage bucket

The bundle's config is the easy half. Provisioning the bucket and getting the
permissions right is the part that costs an afternoon, so this is the whole
procedure, verified against Hetzner Object Storage in `fsn1` on 2026-08-24.

## What Hetzner gives you

S3-compatible object storage, one endpoint per region:

```
https://fsn1.your-objectstorage.com
```

Credentials are **per project**, not per bucket — one access key can see every
bucket in the project. There is no per-bucket key, so "give this app its own
credentials" is not a thing you can do here; isolation is by bucket name and by
who holds the key.

Access keys are created in the Hetzner Cloud Console under
**Object Storage → Credentials**. They look like `AE7OOA1GB6SPZ1UF4RYO` — note
they do *not* start with `AKIA`, which is how you can tell at a glance that a
key in an `.env` is Hetzner and not AWS.

## Local CLI profile

Everything below assumes an `aws` profile pointed at Hetzner. In `~/.aws/config`:

```ini
[profile hetzner]
region = fsn1
output = json
endpoint_url = https://fsn1.your-objectstorage.com
```

and in `~/.aws/credentials`:

```ini
[hetzner]
aws_access_key_id = AE7...
aws_secret_access_key = ...
```

The profile name in `credentials` has **no** `profile ` prefix; the one in
`config` does. Getting that backwards produces a confusing "Unable to locate
credentials" against a profile that visibly exists.

Check it:

```bash
aws s3 ls --profile hetzner
```

## Create the bucket

```bash
aws s3 mb s3://myapp --profile hetzner
```

Bucket names are global to the project and cannot be renamed. Existing names in
this project mix two conventions — `mediary-dev`, `dev-scanstation` — so pick
one deliberately rather than by accident.

## Permissions — the part that bites

**A new bucket is entirely private.** Uploading works immediately (the key has
write access), but fetching the object over HTTPS returns **403**. Nothing warns
you; the upload succeeds and the image just does not load.

```bash
echo hi > /tmp/probe.txt
aws s3 cp /tmp/probe.txt s3://myapp/_probe.txt --profile hetzner
curl -o /dev/null -w '%{http_code}\n' https://myapp.fsn1.your-objectstorage.com/_probe.txt
# 403
```

Note the URL shape: **virtual-hosted style**, `https://<bucket>.fsn1.your-objectstorage.com/<key>`.

Two ways to make objects readable. Both are confirmed working on Hetzner.

### Option A — per-object ACL

```bash
aws s3 cp file.jpg s3://myapp/file.jpg --acl public-read --profile hetzner
```

Public one object at a time. Right when a bucket holds a mix of public and
private things. The cost is that every writer has to remember the flag — a
single upload that forgets it is a broken image in production, and it looks
identical to a missing file.

### Option B — bucket policy (recommended when everything is public)

```bash
cat > /tmp/policy.json <<'JSON'
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "PublicRead",
    "Effect": "Allow",
    "Principal": "*",
    "Action": "s3:GetObject",
    "Resource": "arn:aws:s3:::myapp/*"
  }]
}
JSON
aws s3api put-bucket-policy --bucket myapp --policy file:///tmp/policy.json --profile hetzner
```

Takes effect immediately and applies to objects already uploaded — the probe
above starts returning 200 without re-uploading it. Because it grants
`s3:GetObject` to `*`, **everything in the bucket becomes world-readable**, so
this belongs on a bucket that holds only servable assets. Do not reach for it on
a bucket that also holds originals, exports, or anything with a person's name in
it.

Read the policy back to confirm:

```bash
aws s3api get-bucket-policy --bucket myapp --profile hetzner
```

### Option C — keep it private

Leave the bucket closed and serve through something that holds the credentials:
presigned URLs, or imgproxy reading `s3://`. This is what mediary does, and it is
the correct default for anything that is not deliberately public. It costs you a
signing step on every render.

## Wiring it into the app

```dotenv
S3_ENDPOINT=https://fsn1.your-objectstorage.com
AWS_S3_BUCKET_NAME=myapp
AWS_S3_ACCESS_ID=AE7...
AWS_S3_ACCESS_SECRET=...
```

Then `bin/console storage:config` to see the resolved zones and confirm the
adapter is `League\Flysystem\AwsS3V3\AwsS3V3Adapter` against the bucket you
expect — not the local adapter it silently falls back to when the env is empty:

```
 Zone              Adapter                                   Bucket
 archive.storage   League\Flysystem\AwsS3V3\AwsS3V3Adapter   myapp
 local.storage     League\Flysystem\Local\...                —
```

## Things that have actually gone wrong

- **403 on read, 200 on write.** The default state of a new bucket. See above.
- **A key that looks wrong.** Hetzner keys do not start with `AKIA`. A key that
  does is an AWS key in the wrong profile, and it will fail against this endpoint
  with a signature error rather than an auth error.
- **`endpoint_url` missing from the profile.** The CLI then talks to real AWS
  and reports that the bucket does not exist — which is true, on AWS.
- **Region.** Hetzner buckets are regional and the endpoint encodes the region;
  `fsn1` in the endpoint and `region = fsn1` have to agree.
