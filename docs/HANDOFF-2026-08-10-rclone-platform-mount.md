# /platform via rclone mount — PoC findings and next steps

**Date:** 2026-08-10
**Status:** Proof of concept validated locally (Linux). Not yet touched on production or Mac.

## The problem

`APP_DATA_DIR=/platform` is a shared convention used by zm, md, openfoto, mediary, ai-tools, and
mono. Today it points at two completely disconnected disks that happen to share a path:

- **Local (this Linux box):** `/platform -> /media/tac/x10a/v2` (an ext4-formatted external
  drive — not NTFS, corrected from an earlier assumption; either way, not natively readable from
  a Mac without extra software).
- **Production (Dokku):** `dokku storage:mount /mnt/volume-1/platform-data:/platform` — a
  Hetzner block Volume, bind-mounted into the container.

Getting a file (in particular, a SQLite `.folio` file built locally) from one side to the other
today requires a manual, explicit sync step. Goal: eliminate that step by having both sides mount
the *same* live backing store, and treat "the bucket" as the single authority — local and
production both become caches of it, not sources of truth in their own right.

## Direction: `rclone mount` against the existing Hetzner Object Storage bucket

Every app already talks to Hetzner Object Storage (`fsn1.your-objectstorage.com`) via Flysystem
for other purposes (see `md/config/packages/flysystem.yaml`). Same bucket, same credentials —
`rclone mount` just exposes it as a real POSIX-ish path instead of going through the S3 SDK.

Other options considered and ruled out for this specific shape of problem (laptop with
intermittent connectivity ⇄ always-on production box):

- **AWS S3 Files** (NFS-based S3 mount AWS launched Apr 2026) — AWS compute only, and confirmed
  (via AWS's own docs) that it does **not** support cross-client `flock`/`fcntl` locking, so it
  doesn't even solve the SQLite correctness question it looks like it would.
- **LiteFS** (Fly.io) — architecturally a poor fit: assumes a fixed primary with a direct,
  persistent network connection from every replica. A laptop that sleeps/roams networks/sits
  behind NAT doesn't fit that model. Its sibling **Litestream** is closer in spirit (ships WAL to
  S3-compatible storage continuously) but it's a one-directional backup/restore tool, not a live
  bidirectional mount.
- **JuiceFS** — the "do it properly" option if we ever need real multi-writer POSIX locking
  (metadata engine + S3 data store, genuine `flock`/`fcntl` across hosts). Heavier to run. Not
  needed given the single-writer-at-a-time requirement below.

## What we actually tested (this session, Linux only)

1. Installed rclone `v1.75.0` (both to `~/.local/bin` and system-wide via the official
   `curl https://rclone.org/install.sh | sudo bash` installer — the sudo route needs an
   interactive terminal for the password prompt, so run it yourself rather than through an agent
   session).
2. Created an rclone remote (`hetzner-museado`) reusing `md`'s existing
   `AWS_S3_ACCESS_ID` / `AWS_S3_ACCESS_SECRET` / `S3_ENDPOINT` env values — no new credentials:
   ```bash
   rclone config create hetzner-museado s3 \
     provider=Other \
     endpoint=https://fsn1.your-objectstorage.com \
     access_key_id=<AWS_S3_ACCESS_ID value> \
     secret_access_key=<AWS_S3_ACCESS_SECRET value> \
     env_auth=false
   ```
3. Mounted an isolated test prefix (**not** production data) so nothing real was at risk:
   ```bash
   rclone mount hetzner-museado:museado/platform-mount-test ~/platform-mount-test \
     --vfs-cache-mode full \
     --dir-cache-time 15s \
     --vfs-write-back 3s \
     --vfs-cache-max-size 20G \
     --daemon
   ```
4. **Round-trip smoke test:** wrote a file locally through the mount, confirmed it appeared in
   the real bucket (verified via a fresh `rclone ls`, bypassing any local cache) ~5s later —
   matches the `--vfs-write-back` setting.
5. **Real stress test — the important one:** copied 25 real `.folio` SQLite files (272KB–5MB
   each) plus a provider's `provider.json`/`dataset.json` from the real `/platform` onto the
   mount, then ran the actual `dataset:scan` command (`Survos\StorageBundle`'s sibling in
   `dataset-bundle`) against it:
   ```bash
   APP_DATA_DIR=/home/tac/platform-mount-test php bin/console dataset:scan -v
   ```
   This opens every `.folio` file via PDO and runs `SELECT COUNT(*)` — a genuine test of SQLite
   reads over the mount, not just plain file I/O. **Result: correct row counts and file sizes for
   every dataset, identical to what the same files report when read straight off `x10a`. No
   corruption, no errors.**
6. **Timing:** 7.1s for those 25 files over the mount vs. 2.5s for the *entire* real local
   `/platform/folio` tree via the same command (which is far bigger — includes e.g.
   `smith/nmaahc` at 41,646 rows / 152MB). Clearly slower per-file over the mount, as expected —
   well inside the "even if it's slower" tolerance we're designing for.

## Config knobs that matter (and current recommendations)

| Flag | Default | What it controls | Notes |
|---|---|---|---|
| `--vfs-cache-mode` | off | Must be `full` for this use case | Caches whole files locally, enables partial random-access reads/writes |
| `--dir-cache-time` | 5m | How long before a read re-checks the remote for changes made elsewhere | Safe to set very long (tested 15s; could go to minutes/hours) — purely a freshness knob, no data-loss risk either way |
| `--vfs-write-back` | 5s | How long after your last write to a file before it's pushed to the bucket | Safe to lengthen — data sits safely in the local cache the whole time, and rclone auto-resumes any un-uploaded writes on next start even after a crash/kill. Longer = fewer redundant re-uploads during an active multi-hour edit session, at the cost of a bigger window before another machine can see the change |
| `--vfs-cache-max-size` | off (unbounded) | Hard cap on local cache disk usage | **Must set explicitly** — see disk space section below |
| `--cache-dir` | `~/.cache/rclone` | Where the local cache lives | Override this to point the cache at a different disk (see Mac section) |

For the stated workflow — edit locally for hours, then do a deliberate "big push and
re-normalize" — `--dir-cache-time` and `--vfs-write-back` can both be turned up well past their
defaults without any downside; we only used short values here to make the PoC's behavior visible
quickly.

## Disk space reality check (blocks unbounded caching)

- Real `/platform` (x10a) total: **544GB**
- This Linux box's internal drive: 285GB total, only **49GB free** (83% used)
- rclone's default cache dir already lives on the internal drive (not x10a) — correct default,
  since the cache is disposable/reconstructable and doesn't need to be on any particular disk

Because there isn't enough free space to cache the whole dataset locally, `--vfs-cache-max-size`
must be set explicitly (tested with `20G`) so the cache evicts least-recently-used files once it
hits that ceiling, instead of racing free disk space. Default eviction
(`--vfs-cache-max-age 1h`) won't save you mid-session — anything actively touched counts as
"recently accessed" and won't be evicted until you stop touching it.

## Open items / not yet done

- **Production not touched.** No changes made to any Dokku config or the real `/platform`
  symlink. If/when we pilot this for real, the plan discussed was: run `rclone mount` as a
  systemd service on the Dokku host, then swap `dokku storage:mount ...:/platform` to point at
  the rclone mount path instead of the Volume — same bind-mount mechanism Dokku already uses, no
  container changes. Start with a low-stakes app (`ai-tools` or `sleekdb-demo`) before anything
  that matters.
- **"Force flush now" command not verified.** Wanted to confirm the exact `rclone rc vfs/...`
  call to force-push pending writes on demand (for a hard guarantee at the moment of a "big
  push," rather than trusting the write-back timer) — the RC server didn't come up cleanly in
  this session's daemon restart. Worth revisiting if timer-based flushing ever feels imprecise.
- **Mac setup — not started.** This session runs on Linux only; nothing was done on the Mac.
  Checklist for when that machine is available:
  1. `brew install rclone`
  2. Install **macFUSE** — a third-party system extension, not built into macOS. Requires
     explicit approval in System Settings → Privacy & Security, and on some macOS/Apple Silicon
     combinations an additional Recovery Mode (reduced security) step. Do this ahead of time, not
     mid-test.
  3. Recreate the `hetzner-museado` remote (same `rclone config create` command above, same
     credentials — nothing OS-specific about the remote itself).
  4. **Check actual free disk space before deciding cache location** — an M4 MacBook may have
     enough headroom to keep the cache on the internal SSD (simplest, fastest); if not, use
     `--cache-dir` to point it at an external disk instead. Either way this is a one-flag decision,
     not a structural one — don't need to pick now.
  5. Given a home connection around 30Mbps down, the working-set size (how much you keep "warm"
     via `--vfs-cache-max-size`, or how many files you `dataset:scan`/normalize in one session)
     is the practical throughput constraint, more than disk space itself in most cases.
- **Goal beyond dev convenience:** being able to browse/query production's real data directly
  through a mount (open a `.folio` file, run a query) instead of building and calling one-off
  APIs to inspect production state. Not tested against production yet, but that's the same
  mechanism as the local test above — mount the bucket, point read-only tooling at it.

## How to reproduce / pick this back up

Test mount data lives isolated under the `platform-mount-test/` prefix in the `museado` bucket —
safe to delete any time with `rclone purge hetzner-museado:museado/platform-mount-test`, doesn't
touch anything real.
