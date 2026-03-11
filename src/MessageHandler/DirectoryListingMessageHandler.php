<?php

declare(strict_types=1);

namespace Survos\StorageBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Service\StorageService;

#[AsMessageHandler]
final class DirectoryListingMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageService $storageService,
    ) {}

    public function __invoke(DirectoryListingMessage $message): void
    {
        $storageCode = Storage::calcCode($message->zoneId);
        $storage = $this->em->getRepository(Storage::class)->find($storageCode);
        if (!$storage) {
            throw new \RuntimeException(sprintf('Storage not found for zone %s', $message->zoneId));
        }

        $storageNode = $this->em->getRepository(StorageNode::class)->findOneBy([
            'storage' => $storage,
            'path' => $message->path,
        ]);

        if (!$storageNode) {
            $storageNode = new StorageNode(
                storage: $storage,
                path: $message->path,
                isDir: true
            );
            $storageNode->name = $message->path ?: $message->zoneId . ' root';
            $this->em->persist($storageNode);
            $this->em->flush();
        }

        // list this directory (non-recursive)
        $filesystem = $this->storageService->getZone($message->zoneId);
        $listing = $filesystem->listContents($message->path, false);

        $newDirs = [];

        $dirCount = 0;
        $fileCount = 0;

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
                $child->lastModified = $item->lastModified();
                $child->fileSize = $item->isFile() ? $item->fileSize() : null;

                // populate cheap metadata immediately
                $child->meta = array_filter([
                    'mimeType' => method_exists($item, 'mimeType') ? $item->mimeType() : null,
                ]);

                $this->em->persist($child);
            }

            if ($item->isDir()) {
                $dirCount++;
            } else {
                $fileCount++;
            }
        }

        $storageNode->dirCount = $dirCount;
        $storageNode->fileCount = $fileCount;
        $storageNode->status = StorageNode::STATUS_DIR_LOADED;

        $this->em->flush();
    }
}
