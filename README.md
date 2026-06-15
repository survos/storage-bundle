# StorageBundle

A Symfony bundle to interact with storage (via Flysystem).  It exposes commands, controllers and twig utilities.  All of the underlying storage happens through Flysystem.



## Quickstart
```bash
symfony new storage-demo --webapp && cd storage-demo
composer require survos/storage-bundle
```

Optional thumbnail support:

```bash
composer require survos/imgproxy-bundle
```

Without imgproxy, the bundle still works normally and falls back to direct object links instead of generated image thumbnails/previews.

Configure Flysystem, including the relevant env vars if using something besides local

```yaml
# config/packages/flysystem.yaml
# Read the documentation at https://github.com/thephpleague/flysystem-bundle/blob/master/docs/1-getting-started.md
flysystem:
    storages:
        default.storage:
            adapter: 'aws'
            # visibility: public # Make the uploaded file publicly accessible in S3
            options:
                client: 'Aws\S3\S3Client' # The service ID of the Aws\S3\S3Client instance
                bucket: '%env(AWS_S3_BUCKET_NAME)%'
                streamReads: true
                prefix: '%env(S3_STORAGE_PREFIX)%'
when@dev:
    flysystem:
        storages:
            default.storage:
                adapter: 'local'
                options:
                    directory: '%kernel.project_dir%/public/storage'
```



```bash
symfony new storage-demo --webapp && cd storage-demo
composer require survos/storage-bundle
bin/console storage:config
bin/console storage:list
```

You can browse interactively with the basic admin controller.

```bash
composer require survos/simple-datatables-bundle
symfony server:start -d
symfony open:local --path=/storage/zones
```

Storage zones (and their credentials) are configured in `flysystem.yaml` as shown above —
local during dev, S3/Bunny/etc. in production via env vars.

To see what got wired at runtime, run the read-only diagnostic:

```bash
bin/console storage:config
```

It dumps the resolved zones and their adapters (class, root/bucket), which is handy for
confirming env vars resolved correctly. It makes no changes and needs no credentials.

> Legacy note: earlier versions shipped a Bunny-specific `storage:config <api-key>` that
> fetched per-zone keys from the Bunny dashboard. The bundle is now backend-agnostic
> (Flysystem), so that key-fetcher is gone; `storage:config` is purely diagnostic.

Your application now has a bare-bones controller located at /admin/storage, you may want to secure this route in security.yaml, or configure it in config/routes/survos_storage.yaml.

You also have access to a command line interface.

```bash
bin/console storage:list 
```

```bash
+------------- museado/ -----+--------+
| ObjectName     | Path      | Length |
+----------------+-----------+--------+
| photos finales | /museado/ | 0      |
+----------------+-----------+--------+


```
