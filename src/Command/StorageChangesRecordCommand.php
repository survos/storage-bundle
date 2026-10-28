<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Command;

use Survos\StorageBundle\Service\StorageService;
use Survos\StorageBundle\Tracking\SidecarCache;
use Survos\StorageBundle\Tracking\StorageJournal;
use Survos\StorageBundle\Tracking\TrackingConfig;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('storage:changes:record', 'Record a confirmed external object write, such as an Imagor result')]
final readonly class StorageChangesRecordCommand
{
    public function __construct(private StorageJournal $journal, private StorageService $storage, private TrackingConfig $config)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Tracked Flysystem zone')] string $zone,
        #[Argument('Object key relative to the zone prefix')] string $path,
        #[Option('Opaque object ETag from the producer')] ?string $etag = null,
        #[Option('Object version id from the producer')] ?string $versionId = null,
    ): int {
        if (!$this->config->enabled($zone)) {
            throw new \InvalidArgumentException('Tracking is disabled for '.$zone);
        }
        if (!$this->storage->getZone($zone)->fileExists($path)) {
            throw new \RuntimeException('Object is not durably available: '.$path);
        }
        $id = $this->journal->record($zone, $path, revision: array_filter([
            SidecarCache::ETAG => $etag, SidecarCache::VERSION_ID => $versionId,
        ], static fn ($value) => null !== $value));
        $io->success(sprintf('Recorded storage change %d.', $id));

        return Command::SUCCESS;
    }
}
