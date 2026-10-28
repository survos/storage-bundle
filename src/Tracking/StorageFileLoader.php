<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Event\StorageFileLoadedEvent;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Called exclusively by the StorageNode workflow; its executor owns the flush. */
final readonly class StorageFileLoader
{
    public function __construct(private StorageService $storage, private TrackingConfig $config, private EventDispatcherInterface $events)
    {
    }

    public function load(StorageNode $node): void
    {
        if ($node->isDir) {
            throw new \LogicException('Expected a file StorageNode');
        }
        $fs = $this->storage->getZone($node->storageId);
        $node->meta[StorageNode::EXISTS] = $fs->fileExists($node->path);
        if (!$node->meta[StorageNode::EXISTS]) {
            unset($node->meta[StorageNode::CONTENT], $node->meta[StorageNode::CONTENT_SHA256], $node->meta['ai']);
            $node->fileSize = null;
            $node->lastModified = null;
        } else {
            if ($this->config->cacheContents($node->storageId, $node->path)) {
                $bytes = $fs->read($node->path);
                $node->meta[StorageNode::CONTENT] = $bytes;
                $node->meta[StorageNode::CONTENT_SHA256] = hash('sha256', $bytes);
                $node->fileSize = strlen($bytes);
            } else {
                $node->fileSize = $fs->fileSize($node->path);
            }
            $node->lastModified = (new \DateTime())->setTimestamp($fs->lastModified($node->path));
        }
        $this->events->dispatch(new StorageFileLoadedEvent($node));
    }
}
