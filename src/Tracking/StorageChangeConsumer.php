<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageChange;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Workflow\DirectoryWorkflow;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Symfony\Component\Lock\LockFactory;

/** Forward durable invalidations to the StorageNode workflow; never fetch files here. */
final readonly class StorageChangeConsumer
{
    public function __construct(private EntityManagerInterface $em, private StorageJournal $journal, private TransitionExecutorInterface $executor, private WorkflowCatalog $catalog, private TrackingConfig $config, private LockFactory $locks)
    {
    }

    public function consume(int $limit = 100): int
    {
        $lock = $this->locks->createLock('survos.storage.changes', 300);
        if (!$lock->acquire()) {
            return 0;
        }
        try {
            $count = 0;
            foreach ($this->journal->pending($limit) as $id) {
                $lock->refresh();
                $this->em->clear();
                $change = $this->em->find(StorageChange::class, $id);
                if (null === $change || null !== $change->processedAt) {
                    continue;
                }
                if (!$this->config->enabled($change->zone)) {
                    throw new \LogicException('Tracking disabled for '.$change->zone);
                }
                if (StorageChange::DELETE_DIRECTORY === $change->operation) {
                    foreach ($this->em->getRepository(StorageNode::class)->findBy(['storage' => $change->zone, 'isDir' => false]) as $node) {
                        if ('' === $change->path || str_starts_with($node->path, rtrim($change->path, '/').'/')) {
                            $this->queue($node);
                        }
                    }
                } else {
                    $storage = $this->em->find(Storage::class, $change->zone);
                    if (null === $storage) {
                        $storage = new Storage($change->zone);
                        $this->em->persist($storage);
                    }
                    $node = $this->em->find(StorageNode::class, StorageNode::calcCode($change->zone, $change->path));
                    $new = null === $node;
                    if ($new) {
                        $node = new StorageNode($storage, $change->path, StorageChange::DIRECTORY === $change->operation);
                        $node->name = basename($change->path);
                        $this->em->persist($node);
                    }
                    $node->meta[SidecarCache::EVENT_REVISION] = $change->revision;
                    $this->em->flush(); // New nodes queue through built-in Kickoff.
                    if (!$new) {
                        $this->queue($node);
                    }
                }
                // A crash between dispatch and this flush causes a safe duplicate invalidation.
                $change->processedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $this->em->flush();
                ++$count;
            }

            return $count;
        } finally {
            $lock->release();
        }
    }

    private function queue(StorageNode $node): void
    {
        $this->executor->execute($this->catalog->get(DirectoryWorkflow::NAME), $node, $node->isDir ? DirectoryWorkflow::FETCH : DirectoryWorkflow::LOAD);
    }
}
