<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Workflow\DirectoryWorkflow;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;

final readonly class DirectoryScanStarter
{
    public function __construct(
        private EntityManagerInterface $em,
        private StorageService $storageService,
        private TransitionExecutorInterface $executor,
        private WorkflowCatalog $catalog,
    ) {
    }

    public function start(string $zone, string $path, bool $sync = false, bool $loadFiles = false): StorageNode
    {
        $start = function () use ($zone, $path, $loadFiles): StorageNode {
            $this->storageService->getZone($zone);
            $path = \trim($path, '/');
            $storage = $this->em->find(Storage::class, Storage::calcCode($zone));
            if (null === $storage) {
                $storage = new Storage(Storage::calcCode($zone));
                $this->em->persist($storage);
            }
            $node = $this->em->find(StorageNode::class, StorageNode::calcCode($storage, $path));
            $request = ['id' => (new \Random\Randomizer())->getBytesFromString('0123456789abcdef', 16), 'requestedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'root' => $path, 'recursive' => true, StorageNode::LOAD_FILES => $loadFiles];
            if (null === $node) {
                $node = new StorageNode($storage, $path, isDir: true);
                $node->name = '' === $path ? $zone.' root' : \basename($path);
                $node->meta[StorageNode::SCAN_REQUEST] = $request;
                $this->em->persist($node);
                // Kickoff dispatches once, only after the new root is committed.
                $this->em->flush();
            } else {
                $node->meta[StorageNode::SCAN_REQUEST] = $request;
                // Resume a previously saved root whose dispatch failed.
                $this->executor->execute($this->catalog->get(DirectoryWorkflow::NAME), $node, DirectoryWorkflow::FETCH);
            }

            return $node;
        };

        return $sync ? $this->executor->synchronously($start) : $start();
    }
}
