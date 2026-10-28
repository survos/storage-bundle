<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

use Survos\StorageBundle\Service\StorageService;

/** For a caller that has confirmed a remote producer's S3 write succeeded. */
final readonly class ExternalStorageWrite
{
    public function __construct(private StorageService $storage, private StorageJournal $journal, private TrackingConfig $config)
    {
    }

    public function record(string $bucket, string $key, array $revision = []): array
    {
        $ids = [];
        foreach ($this->config->zones() as $zone) {
            $adapter = $this->storage->getAdapterModel($zone);
            $prefix = trim((string) $adapter->prefix, '/');
            $prefix = '' === $prefix ? '' : $prefix.'/';
            if ($adapter->bucket === $bucket && str_starts_with($key, $prefix)) {
                $ids[] = $this->journal->record($zone, substr($key, strlen($prefix)), revision: $revision);
            }
        }

        return $ids;
    }
}
