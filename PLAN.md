# Storage Bundle Plan

## Current Direction

The bundle is converging on a single traversal primitive centered on `DirectoryListingMessage` and `storage:iterate`.

Conceptually, the system does one thing:

- walk a storage zone/path
- optionally do so sync or async
- allow handlers/listeners to react

Persistence, debug output, and later application-specific file work should be treated as reactions to traversal, not as separate traversal mechanisms.

## Agreed Boundaries

- `DirectoryListingMessage` is a directory traversal command.
- The bundle should not dispatch per-file messages.
- File-specific follow-up belongs in the application layer, after directory traversal has discovered and exposed file data.
- Lifecycle notifications such as pre-iterate and post-load are events, not alternate `DirectoryListingMessage` types.

## Command Model

`storage:iterate` is becoming the execution primitive.

It can be used:

- sync, for immediate traversal and debugging
- async, for queued traversal
- with persistence handlers enabled
- with debug/list-style handlers enabled

This means `storage:iterate` is not "non-persisting" by definition. Persistence is a handler concern.

## Relationship Between Commands

### `storage:iterate`

This is the generic traversal entrypoint.

- dispatches directory traversal messages
- may run sync or async
- may trigger persistence, debug output, or application listeners depending on registered handlers

### `storage:populate`

This remains a useful intent-level command for applications that want to build or refresh a local cache.

Semantically, it is close to:

- iterate
- with persistence enabled
- typically aimed at local cache population

It may eventually become a thin wrapper around the same traversal engine used by `storage:iterate`.

### `storage:list`

This is increasingly similar to:

- `storage:iterate`
- in sync mode
- with debug/list output enabled
- without persistence as the primary goal

It may remain as a user-facing convenience command even if the implementation is unified.

## Likely End State

Internally:

- one traversal engine / orchestration path
- one message protocol for directory traversal
- optional listeners/handlers for persistence, debug output, and app-specific work

At the CLI:

- either keep multiple intent-based commands as thin wrappers
- or consolidate carefully, without creating contradictory or muddy flags

Current bias:

- unify implementation first
- keep clear intent-based commands (`iterate`, `populate`, `list`) if they remain useful to operators

## Open Questions

- Should `storage:list` remain a direct inspection command, or become a thin sync/debug wrapper around traversal?
- Should `storage:populate` remain a named wrapper for cache-building, even if it shares nearly all of its implementation with `storage:iterate`?
- If command unification happens, which flags are semantically clean enough to expose without making the CLI ambiguous?

## Related Bundle: import-bundle

`import-bundle` already has a very similar command: `import:dir`.

Observed similarities:

- it treats directory traversal as the primitive
- it walks a nested tree and emits both directory and file records
- it uses Symfony events for downstream enrichment rather than overloading one message type
- it keeps file-level hooks as explicit event concerns

Observed differences:

- `import:dir` scans a local directory path rather than a Flysystem storage zone
- its primary output is JSONL DTO rows
- it is oriented around import/export pipelines and enrichment/probing
- `storage-bundle` is oriented around Flysystem-backed traversal and local cache/materialization of storage state

This suggests a likely architectural overlap:

- if `import-bundle` could operate against Flysystem, or
- if `storage-bundle` could optionally emit JSONL,

then the two commands might be close enough to unify around one traversal engine.

Possible outcomes to evaluate later:

- keep both bundles separate, but align them around the same traversal concepts
- extract a shared traversal layer used by both bundles
- deprecate `import-bundle`'s `import:dir` in favor of storage-backed traversal plus optional JSONL export
- add optional JSONL output to `storage-bundle` and let `import-bundle` focus only on downstream import/probe semantics

Current conclusion:

- the overlap is real
- the biggest current difference is not tree walking, but output contract
- `import:dir` writes JSONL DTO rows
- `storage-bundle` targets Flysystem zones and cache/persistence

So any merge should be driven by output responsibilities, not just by the fact that both commands recurse directories.

## Unification Direction (decided)

- Delete `import:dir`. Directory traversal lives in `storage-bundle`.
- Callers that want file access point `storage:iterate` at a zone or at a raw local path/DSN; the bundle wraps raw paths in `LocalFilesystemAdapter` on the fly.
- `ProbeService` moves into `storage-bundle`. Rationale: probing is about the *contents* of files the bundle walks, and deep probes (ffprobe, docx, fpcalc) can't run without access to file bytes — this is the same domain.
- `FinderFileInfo` goes away. Flysystem's `FileAttributes` + a small extension helper is enough. We lose `ctime`; acceptable (populate it in the local fast path if ever needed).

## Dynamic Adapters

A `FilesystemResolver` service accepts either:
- a registered zone id (existing behavior), or
- a path/DSN (`/abs/path`, `file://...`, later `s3://...`) which it wraps in the appropriate adapter.

This removes the "Flysystem zone vs. local path" fork at the command layer — `storage:iterate` takes one argument and internally resolves an adapter.

## LocalFileResolver

Probes above level 1 require a real path on disk. Rather than forking traversal into "local" and "remote" flavors, introduce a resolver:

```php
interface LocalFileResolver
{
    /**
     * Return an absolute local path for the given (filesystem, path).
     * No-op (returns the real path) for LocalFilesystemAdapter.
     * For remote adapters, streams to tempnam() and returns that path.
     * The returned Handle releases the temp file on dispose().
     */
    public function materialize(FilesystemOperator $fs, string $path): LocalFileHandle;
}

final class LocalFileHandle
{
    public readonly string $path;
    public function dispose(): void; // no-op for local, unlink for staged
}
```

Probe levels, re-framed around what the resolver needs:

- **Level 0** — no bytes. Path + extension only.
- **Level 1** — stream-friendly. `size`, `mtime` (from `FileAttributes`), `mime_type` via `finfo_buffer()` on first 8 KB of `readStream()`, `xxh3` via `hash_update_stream()` over `readStream()`. Works on any adapter with no staging.
- **Level 2** — needs a real path. `ffprobe`, `ZipArchive` (docx). Calls `LocalFileResolver::materialize()`, probes, disposes.
- **Level 3** — needs a real path. `fpcalc` audio fingerprint. Same pattern.

Staging cost is only paid when the adapter is remote *and* the probe level demands it. Local adapters are free.

## .gitignore Support

Flysystem has no equivalent to Finder's gitignore handling. Rather than keep a second (Finder) adapter just for this, implement it as a traversal-time filter that works on any Flysystem adapter:

```php
final class GitignoreFilter implements TraversalFilter
{
    public function __construct(private GitignoreParser $parser) {}

    public function accept(FilesystemOperator $fs, string $path, bool $isDir): bool;
}
```

- On entering a directory, the filter reads `.gitignore` via `$fs->read()` if present and pushes a rule set onto a stack.
- On leaving the directory, it pops.
- Rules are matched against the path relative to the `.gitignore` location, honoring `!` negations and directory-only patterns.
- Root `.gitignore` + nested `.gitignore` files both work. `.git/info/exclude` and the global excludes file are ignored by default (can opt in if a local adapter is detected).

Parser: either a small in-repo implementation (gitignore semantics are compact) or a tagged dependency (e.g. a gitignore-matcher package). No shelling out to `git check-ignore`, because that only works for local adapters and we want one code path.

## Migration Steps

1. Add `FilesystemResolver` + accept path/DSN in `storage:iterate`.
2. Move `ProbeService` (+ `ImportDirFileEvent` → a storage-bundle file event) into storage-bundle. Rewrite it against `FilesystemOperator` + `LocalFileResolver`.
3. Add `LocalFileResolver` with local fast path and tempnam staging.
4. Add `GitignoreFilter` + traversal filter hook.
5. Add a JSONL sink listener (replaces `JsonlWriter` use inside `import:dir`).
6. Delete `ImportDirCommand`, `FinderFileInfo`, and the Finder-based walk. Keep `Directory`/`File` DTOs if they remain the richer contract; otherwise port their useful fields onto a storage-bundle DTO.
7. `import-bundle` keeps only import/conversion/enrichment concerns (convert, entities, probe reports, providers).

## Open Questions (revised)

- Do we need `ctime` anywhere? If yes, the local fast path can populate it; the remote path cannot.
- Which gitignore parser — in-repo or dependency? Prefer a small vetted dependency if one exists; otherwise ~200 lines in-repo.
- Should JSONL output be a first-class CLI flag on `storage:iterate` (`--jsonl=out.jsonl`) or only via DI-wired listener? Bias: flag, to preserve `import:dir`'s UX for scripts.
