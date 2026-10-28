<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Workflow;

use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Service\DirectoryScanner;
use Survos\StorageBundle\Tracking\StorageFileLoader;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowMessengerBundle\Attribute\Transition;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Event\TransitionEvent;

#[AsWorkflow(name: self::NAME, supports: [StorageNode::class], markingProperty: 'status')]
final class DirectoryWorkflow
{
    public const NAME = 'storage_node';
    public const TRANSPORT = 'storage.fetch';
    #[Place('Node waiting to load', initial: true, next: [self::FETCH, self::LOAD])]
    public const NEW = StorageNode::STATUS_NEW;
    #[Place('Previously queued directory')]
    public const NEW_DIR = StorageNode::STATUS_NEW_DIR;
    #[Place('Previously discovered file')]
    public const NEW_FILE = StorageNode::STATUS_NEW_FILE;
    #[Place('Direct children saved')]
    public const LOADED = StorageNode::STATUS_DIR_LOADED;
    #[Place('File metadata and optional contents saved')]
    public const FILE_LOADED = StorageNode::STATUS_FILE_LOADED;

    #[Transition(self::NEW, self::LOADED, 'List direct children', async: true, transport: self::TRANSPORT)]
    #[Transition(self::NEW_DIR, self::LOADED, 'List direct children', async: true, transport: self::TRANSPORT)]
    #[Transition(self::LOADED, self::LOADED, 'List direct children', async: true, transport: self::TRANSPORT)]
    public const FETCH = 'fetch_directory';
    #[Transition(self::NEW, self::FILE_LOADED, 'Load file and optional contents', async: true, transport: self::TRANSPORT)]
    #[Transition(self::NEW_FILE, self::FILE_LOADED, 'Load file and optional contents', async: true, transport: self::TRANSPORT)]
    #[Transition(self::FILE_LOADED, self::FILE_LOADED, 'Load file and optional contents', async: true, transport: self::TRANSPORT)]
    public const LOAD = 'load_file';

    #[\Symfony\Component\Workflow\Attribute\AsGuardListener(self::NAME, self::FETCH)]
    public function guardDirectory(\Symfony\Component\Workflow\Event\GuardEvent $event): void
    {
        $event->setBlocked(!$event->getSubject()->isDir);
    }

    #[\Symfony\Component\Workflow\Attribute\AsGuardListener(self::NAME, self::LOAD)]
    public function guardFile(\Symfony\Component\Workflow\Event\GuardEvent $event): void
    {
        $event->setBlocked($event->getSubject()->isDir);
    }

    public function __construct(private readonly DirectoryScanner $scanner, private readonly StorageFileLoader $files)
    {
    }

    #[AsTransitionListener(self::NAME, self::FETCH)]
    public function fetch(TransitionEvent $event): void
    {
        $this->scanner->scan($event->getSubject());
    }

    #[AsTransitionListener(self::NAME, self::LOAD)]
    public function load(TransitionEvent $event): void
    {
        $this->files->load($event->getSubject());
    }
}
