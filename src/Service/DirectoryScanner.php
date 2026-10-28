<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Event\DirectoryListingPreIterateEvent;
use Survos\StorageBundle\Event\StorageNodeDiscoveredEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** One shallow listing. The caller owns flushing, marking and scheduling. */
final readonly class DirectoryScanner
{
    public function __construct(
        private EntityManagerInterface $em,
        private StorageService $storageService,
        private EventDispatcherInterface $events,
    ) {
    }

    /** @return list<StorageNode> Direct child directories. */
    public function scan(StorageNode $node): array
    {
        if (!$node->isDir || null === $node->storage || null === $node->path) {
            throw new \InvalidArgumentException('A scan needs a directory, storage and path.');
        }
        $zoneId = $node->storageId;
        $this->events->dispatch(new DirectoryListingPreIterateEvent($zoneId, $node->path, $node->storage, $node));
        $directories = [];
        $files = 0;
        foreach ($this->storageService->getZone($zoneId)->listContents($node->path, false) as $item) {
            $id = StorageNode::calcCode($node->storage, $item->path());
            $child = $this->em->find(StorageNode::class, $id);
            $isNew = null === $child;
            if ($isNew) {
                $child = new StorageNode($node->storage, $item->path(), $item->isDir());
            }
            if ($child->isDir !== $item->isDir()) {
                throw new \LogicException('Storage entry changed between file and directory: '.$item->path());
            }
            $changed = $child->fileSize !== ($item->isFile() ? $item->fileSize() : null) || $child->lastModified?->getTimestamp() !== $item->lastModified();
            $child->parent = $node;
            $child->name = \basename($item->path());
            $modified = $item->lastModified();
            $child->lastModified = \is_int($modified) ? (new \DateTime())->setTimestamp($modified) : null;
            $child->fileSize = $item->isFile() ? $item->fileSize() : null;
            $this->events->dispatch(new StorageNodeDiscoveredEvent($child, $item, $isNew, $changed));
            if ($isNew) {
                $this->em->persist($child);
            }
            if ($item->isDir()) {
                $directories[] = $child;
            } else {
                ++$files;
            }
        }
        $node->dirCount = \count($directories);
        $node->fileCount = $files;

        return $directories;
    }
}
