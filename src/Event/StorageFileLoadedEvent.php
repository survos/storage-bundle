<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Event;

use Survos\StorageBundle\Entity\StorageNode;

/** Optional application enrichment before the workflow executor flushes the node. */
final readonly class StorageFileLoadedEvent
{
    public function __construct(public StorageNode $node)
    {
    }
}
