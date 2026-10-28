<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

/** Storage receipt vocabulary (ETags are opaque, not content hashes). */
final class SidecarCache
{
    public const SHA256 = 'sha256';
    public const EVENT_REVISION = 'storage_event_revision';
    public const ETAG = 'etag';
    public const VERSION_ID = 'version_id';
    public const SOURCE_REVISION = 'source_revision';
}
