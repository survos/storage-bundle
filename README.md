# StorageBundle

[![Latest Stable Version](https://img.shields.io/packagist/v/survos/storage-bundle)](https://packagist.org/packages/survos/storage-bundle)
[![License](https://img.shields.io/badge/license-MIT-informational)](LICENSE)

A Symfony bundle to interact with storage (via Flysystem).  It exposes commands, controllers and twig utilities.  All of the underlying storage happens through Flysystem.

## Used in production by

- [kpa](https://kpa.survos.com)
- [md](https://musdig.survos.com)
- [mediary](https://mediary.survos.com)
- [showcase](https://showcase.survos.com)
- [zm](https://zm.survos.com)

_(derived from showcase's `Component`/`Site` scan — apps whose `composer.json` requires `survos/storage-bundle` and have a deployed dokku site)_

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


## Live browsing and persisted inventory

Flysystem browsing lists the current backend directly. Doctrine inventory stores
StorageNode records with parent/child relationships; tree-bundle is required for
hierarchy support. Browsing a zone does not require populating its inventory.

## Populate through the StorageNode workflow

Flysystem remains authoritative. Doctrine stores a disposable inventory. A
StorageNode is either a directory or a file; sidecars are ordinary files.

```yaml
survos_storage:
    tracking_lock_factory: lock.workflow.factory
    storages:
        archive.storage:
            track: true
            sidecar_prefix: o
            content_patterns: ['o/*.json']
```

```sh
bin/console storage:populate archive.storage o
bin/console messenger:consume storage.fetch --concurrency=8
```

Use a shared workflow lock store and configure the `storage.fetch` transport.
`--concurrency` needs Symfony Messenger's amphp/parallel support; otherwise run
multiple normal workers. `sync` must also be configured for explicit `--sync` use.

Persisting a node starts the built-in workflow Kickoff after commit. The initial
`new` place selects `fetch_directory` for directories or `load_file` for files.
Each directory transition performs one shallow listing, saves its children, and
lets their own Kickoff continue the traversal. Each file transition loads metadata
and, when its path matches `content_patterns`, stores the file bytes in
`meta[StorageNode::CONTENT]` and their SHA-256 in `meta[StorageNode::CONTENT_SHA256]`.
File contents are fetched only inside the asynchronous file transition.

Running `storage:populate` again visits existing directories and queues files when
size/last-modified changed, contents are missing, or their loading is unfinished.
There is no separate cache rebuild. Delete the disposable StorageNodes and use
`storage:populate` to populate them again. `--dispatch`/`--recursive` are retained
for compatibility; `--sync` is only an explicit diagnostic execution scope.

Applications may listen for `StorageNodeDiscoveredEvent` during listing or
`StorageFileLoadedEvent` after loading, before the executor flushes. No AI-specific
listener or association to an original object is needed to cache a sidecar.
Mediary keeps one file per media ID and task, at the existing
`MediaKeyService::archivePathFromKey($id, $task.'.json', 'o')` path. Its
`CachedSidecarReader` derives that path and decodes the cached node contents.
The S3 payload is unchanged. Tasks remain independent because they are different
files. A cache miss falls back to storage; a cache hit performs no S3 request.

## Tracked writes and changelog

`track: true` wraps the Flysystem adapter so directly injected operators and
StorageService writes are tracked alike. Successful writes, streams, moves,
copies, deletes and visibility changes append durable Doctrine journal rows.
Failed backend writes and reads append nothing. Apply the application migrations
for `StorageChange` and `StorageJournalHead` before enabling tracking. Journal rows
have no foreign keys to disposable StorageNodes. Redis is not required.

`storage:changes:consume` forwards journal entries to the same StorageNode workflow
and marks them dispatched. It never loads files itself. Mediary's Scheduler runs
this forwarding command every minute. A crash after dispatch can produce a safe
duplicate invalidation. Workers read the current file, so delayed messages do not
restore old content. Missing files are marked `meta[StorageNode::EXISTS] = false`
and their cached contents are removed. Per-node workflow locks serialize updates.

The admin-only `GET /storage/changes?after=0&limit=100` endpoint returns `events`
and a `next` cursor. Without authorization it returns 403. Sequence allocation is
serialized through database commit; rows remain available after dispatch and
clients checkpoint after processing a page. There is no automatic pruning.

Filesystem writes and SQL journal inserts cannot be atomic. Journal failures are
reported even if the file was saved: retry or explicitly record the successful
write. Writes bypassing Flysystem require provider notifications or an explicit
receipt; timestamps can detect changes on subsequent listings, but cannot prove
content identity. ETags are opaque, not necessarily content hashes.

```php
$journal->record('archive.storage', $relativeKey, revision: [
    SidecarCache::ETAG => $etag,
    SidecarCache::VERSION_ID => $versionId,
]);
// Maps confirmed bucket/key receipts through configured zone prefixes:
$externalStorageWrite->record($bucket, $key, $revision);
```

`storage:changes:record archive.storage path/to/object` checks existence before
recording a manual receipt; `--etag` and `--version-id` are optional. Notification
provenance is saved under `meta.storage_event_revision`, separately from the hash
of the actual cached contents. Existing AI response provenance is left unchanged.

Mediary records Imagor originals only after its existing HEAD confirms persistence,
including ETag/version when available. Resize HTTP success alone does not identify
or confirm a persisted result object; its producer must supply a confirmed key
before using the same recording API. No guessed resize keys are journaled.

### Browser visibility

`survos_storage.storages` configures tracking (`track`, `sidecar_prefix`,
`content_patterns`); Flysystem still defines the storage connections. The optional
`survos_storage.browse_storages` list selects service IDs for the storage overview
and navbar. Omit it or use `[]` to show all configured stores. It is a presentation
filter, not access control: underlying services and direct storage routes remain
available.

```yaml
survos_storage:
    browse_storages: [local_vault.storage, platform_dfolio.storage]
```
