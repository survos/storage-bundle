<?php

declare(strict_types=1);

namespace Survos\StorageBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Event\DirectoryListingPostLoadEvent;
use Survos\StorageBundle\Event\DirectoryListingPreIterateEvent;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\StorageService;
use InvalidArgumentException;
use DateTime;
use function array_filter;
use function basename;
use function is_int;
use function method_exists;
use function trim;

#[AsMessageHandler]
final class DirectoryListingMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageService $storageService,
        private readonly MessageBusInterface $bus,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function __invoke(DirectoryListingMessage $message): void
    {
        if ($message->type !== StorageNode::TYPE_DIR && $message->type !== DirectoryListingMessage::LOAD) {
            throw new InvalidArgumentException('DirectoryListingMessageHandler only handles directory listing messages.');
        }

        $path = trim($message->path, '/');
        $storageCode = Storage::calcCode($message->zoneId);
        $storage = $this->em->getRepository(Storage::class)->find($storageCode);
        if (!$storage) {
            $this->storageService->getZone($message->zoneId);
            $storage = new Storage($storageCode);
            $this->em->persist($storage);
            $this->em->flush();
        }

        $storageNode = $this->em->getRepository(StorageNode::class)->findOneBy([
            'storage' => $storage,
            'path' => $path,
        ]);

        if (!$storageNode) {
            $storageNode = new StorageNode(
                storage: $storage,
                path: $path,
                isDir: true
            );
            $storageNode->name = $path !== '' ? basename($path) : $message->zoneId . ' root';
            $this->em->persist($storageNode);
            $this->em->flush();
        }

        $this->eventDispatcher->dispatch(new DirectoryListingPreIterateEvent(
            zoneId: $message->zoneId,
            path: $path,
            storage: $storage,
            storageNode: $storageNode,
        ));

        // list this directory (non-recursive)
        $filesystem = $this->storageService->getZone($message->zoneId);
        $listing = $filesystem->listContents($path, false);

        $dirCount = 0;
        $fileCount = 0;
        $toDispatch = [];

        foreach ($listing as $item) {
            $childPath = $item->path();

            $child = $this->em->getRepository(StorageNode::class)->findOneBy([
                'storage' => $storage,
                'path' => $childPath,
            ]);

            if (!$child) {
                $child = new StorageNode(
                    storage: $storage,
                    path: $childPath,
                    isDir: $item->isDir()
                );
                $child->parent = $storageNode;
                $child->name = basename($childPath);
                $lastMod = $item->lastModified();
                $child->lastModified = is_int($lastMod)
                    ? (new DateTime())->setTimestamp($lastMod)
                    : $lastMod;
                $child->fileSize = $item->isFile() ? $item->fileSize() : null;
                $child->meta = array_filter([
                    'mimeType' => method_exists($item, 'mimeType') ? $item->mimeType() : null,
                ]);
                $this->em->persist($child);
            }

            $childDepth = $path === '' ? 1 : substr_count($path, '/') + 2;
            $childName  = basename($childPath);

            if ($item->isDir()) {
                $dirCount++;
                $toDispatch[] = new DirectoryListingMessage(
                    zoneId:     $message->zoneId,
                    type:       StorageNode::TYPE_DIR,
                    path:       $childPath,
                    name:       $childName,
                    parentPath: $path,
                    depth:      $childDepth,
                    context:    $message->context,
                );
            } else {
                $fileCount++;
            }
        }

        $storageNode->dirCount = $dirCount;
        $storageNode->fileCount = $fileCount;
        $storageNode->status = StorageNode::STATUS_DIR_LOADED;

        // Flush all StorageNodes to DB before dispatching child messages,
        // so re-entrant (sync) handlers find them via findOneBy rather than
        // triggering an identity map collision.
        $this->em->flush();

        foreach ($toDispatch as $childMessage) {
            $this->bus->dispatch($childMessage);
        }

        $this->eventDispatcher->dispatch(new DirectoryListingPostLoadEvent(
            zoneId:        $message->zoneId,
            path:          $path,
            storage:       $storage,
            storageNode:   $storageNode,
            dirCount:      $dirCount,
            fileCount:     $fileCount,
            totalChildren: $dirCount + $fileCount,
        ));
    }
}
