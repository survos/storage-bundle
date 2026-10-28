<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Event;

use League\Flysystem\StorageAttributes;
use Survos\StorageBundle\Entity\StorageNode;

/** Enrich node.meta or persist an application entity before the listing is flushed.
 * Queue work requiring committed rows from DirectoryListingPostLoadEvent instead.
 */
final readonly class StorageNodeDiscoveredEvent
{
    public function __construct(
        public StorageNode $node,
        public StorageAttributes $attributes,
        public bool $isNew,
        public bool $changed = false,
    ) {
    }
}
