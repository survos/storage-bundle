<?php

declare(strict_types=1);

namespace Survos\StorageBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Event\DirectoryListingPostLoadEvent;
use Survos\StorageBundle\Workflow\DirectoryWorkflow;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/** Keep the existing post-load event after the executor's committed flush. */
#[AsDoctrineListener(event: Events::postFlush)]
final class DirectoryScanEvents implements ResetInterface
{
    /** @var array<int, DirectoryListingPostLoadEvent> */
    private array $pending = [];

    public function __construct(private readonly EventDispatcherInterface $events)
    {
    }

    #[AsCompletedListener(DirectoryWorkflow::NAME, DirectoryWorkflow::FETCH)]
    public function completed(CompletedEvent $event): void
    {
        $node = $event->getSubject();
        if (!$node instanceof StorageNode || null === $node->storage || null === $node->path) {
            throw new \LogicException('Expected a stored directory.');
        }
        $this->pending[\spl_object_id($node)] = new DirectoryListingPostLoadEvent(
            $node->storageId, $node->path, $node->storage, $node,
            $node->dirCount, $node->fileCount, $node->dirCount + $node->fileCount,
        );
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->pending as $id => $event) {
            if (!$args->getObjectManager()->contains($event->storageNode)) {
                continue;
            }
            unset($this->pending[$id]);
            $this->events->dispatch($event);
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
