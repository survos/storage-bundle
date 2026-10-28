<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Command;

use Survos\StorageBundle\Tracking\StorageChangeConsumer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('storage:changes:consume', 'Queue StorageNode workflow transitions for pending storage changes')]
final readonly class StorageChangesConsumeCommand
{
    public function __construct(private StorageChangeConsumer $consumer)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Maximum entries per run')] int $limit = 100): int
    {
        $io->success(sprintf('Queued %d storage changes.', $this->consumer->consume($limit)));

        return Command::SUCCESS;
    }
}
