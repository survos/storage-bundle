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
