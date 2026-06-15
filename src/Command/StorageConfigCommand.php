<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Command;

use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only diagnostic: list the storage zones (Flysystem adapters) resolved at runtime.
 *
 * Replaces the legacy Bunny-era `storage:config <api-key>` key fetcher — zones are now
 * configured in the app's flysystem.yaml; this just shows what got wired.
 */
#[AsCommand('storage:config', 'Show resolved storage zones and adapters')]
final class StorageConfigCommand
{
    public function __construct(
        private readonly StorageService $storageService,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $adapters = $this->storageService->getAdapters();
        if ([] === $adapters) {
            $io->warning('No storage zones are configured. Define flysystem.storage zones in flysystem.yaml.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($adapters as $code => $adapter) {
            $rows[] = [
                $code,
                $adapter->getClass(),
                $adapter->getRootLocation() ?? '—',
                $adapter->getBucket() ?? '—',
            ];
        }

        $io->title('Storage zones');
        $io->table(['Zone', 'Adapter', 'Root', 'Bucket'], $rows);

        return Command::SUCCESS;
    }
}
