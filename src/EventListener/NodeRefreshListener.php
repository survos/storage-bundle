<?php

declare(strict_types=1);

namespace Survos\StorageBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Event\StorageNodeDiscoveredEvent;
use Survos\StorageBundle\Tracking\TrackingConfig;
use Survos\StorageBundle\Workflow\DirectoryWorkflow;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

/** New nodes use Kickoff. Existing directories and changed files resume after flush. */
#[AsDoctrineListener(event: 'postFlush')]
final class NodeRefreshListener implements ResetInterface
{
    private array $pending = [];

    public function __construct(private TransitionExecutorInterface $executor, private WorkflowCatalog $catalog, private TrackingConfig $config)
    {
    }

    #[AsEventListener]
    public function discovered(StorageNodeDiscoveredEvent $event): void
    {
        $n = $event->node;
        if (!$n->isDir && !($n->meta[StorageNode::SCAN_REQUEST][StorageNode::LOAD_FILES] ?? false)) {
            return;
        }
        if (!$event->isNew && ($n->isDir || $event->changed || StorageNode::STATUS_FILE_LOADED !== $n->status || ($this->config->cacheContents($n->storageId, $n->path) && !isset($n->meta[StorageNode::CONTENT])))) {
            $this->pending[$n->id] = $n;
        }
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $node) {
            if ($event->getObjectManager()->contains($node)) {
                $this->executor->execute($this->catalog->get(DirectoryWorkflow::NAME), $node, $node->isDir ? DirectoryWorkflow::FETCH : DirectoryWorkflow::LOAD);
            }
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
