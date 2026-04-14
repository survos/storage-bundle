<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Event;

use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageNode;

final class DirectoryListingPostLoadEvent
{
    public function __construct(
        public readonly string $zoneId,
        public readonly string $path,
        public readonly Storage $storage,
        public readonly StorageNode $storageNode,
        public readonly int $dirCount,
        public readonly int $fileCount,
        public readonly int $totalChildren = 0,
    ) {}
}
