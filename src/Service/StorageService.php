<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Service;

use Aws\S3\S3ClientInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use Psr\Http\Client\ClientInterface;
use Survos\StorageBundle\Model\Adapter;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class StorageService
{
    /**
     * @param iterable $storageZones <Filesystem[]>
     * @param array $config
     * @param array $zoneMap
     */
    public function __construct(
        #[AutowireIterator('flysystem.storage')] private iterable $storageZones, // no key!
        private array                                    $config = [],
        private array $zoneMap = [] // maps code to zone index
    )
    {
    }

    public function getAdapter(string $storageZone): FilesystemAdapter
    {
        $storageZone = $this->getZone($storageZone);
        $adapter = $this->getPrivateProperty($storageZone, 'adapter');
        return $adapter;
    }

    public function getClient(FilesystemAdapter $adapter): ClientInterface|S3ClientInterface
    {
        return $this->getPrivateProperty($adapter, 'client');
    }

    public function getBucket(FilesystemAdapter $adapter): string
    {
        return $this->getPrivateProperty($adapter, 'bucket');
    }

    public function getZones(): array
    {
        $zones = [];
        foreach (iterator_to_array($this->storageZones) as $idx=>$flysystem) {
            $zones[$this->zoneMap[$idx]] = $flysystem;
        }
        return $zones;
    }

    public function getAdapters(): array
    {
        $adapters = [];
        foreach ($this->storageZones as $idx => $flysystem) {
            $flysystemAdapter = $this->getPrivateProperty($flysystem, 'adapter');
            // now map the adapter private properties
            $adapter = new Adapter(
                $this->zoneMap[$idx],
                $flysystemAdapter::class,
                $this->getPrivateProperty($flysystemAdapter, 'rootLocation'),
                $this->getPrivateProperty($flysystemAdapter, 'bucket')
            );


            $adapters[$this->zoneMap[$idx]] = $adapter;
        }
        return $adapters;
    }

    public function getAdapterModel(string $storageZone): Adapter
    {
        return $this->getAdapters()[$storageZone];

    }

    public function getZone(string $code): Filesystem
    {
        return $this->getZones()[$code];
    }



    // this is the map from index to code, it assumes the order is the same.
    public function addAdapter(string $code, int $index)
    {
        $this->zoneMap[$index] = $code;
    }
    private function getPrivateProperty(mixed $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        if ($reflection->hasProperty($property)) {
            return $reflection->getProperty($property)->getValue($object);
        } else {
            return null;
        }
    }

    public function getStorageZones(): iterable
    {
        return $this->storageZones;

    }

    public function getConfig(): array
    {
        return $this->config;
    }


    /**
     * Read a file's raw bytes from a zone.
     */
    public function downloadFile(string $filename, string $path, ?string $storageZone = null): string
    {
        $zone = $this->getZone($this->resolveZoneCode($storageZone));

        return $zone->read($this->joinPath($path, $filename));
    }

    /**
     * Write content to a zone. Returns the resulting location + size.
     *
     * @param string|resource $body content to write
     * @param array<string, mixed> $headers Flysystem write config
     *
     * @return array{zone: string, path: string, size: int}
     */
    public function uploadFile(
        string  $fileName, // the filename on storage
        mixed   $body, // content to write
        ?string $storageZoneName = null,
        string  $path = '',
        array   $headers = [],
    ): array
    {
        $zoneCode = $this->resolveZoneCode($storageZoneName);
        $zone = $this->getZone($zoneCode);
        $full = $this->joinPath($path, $fileName);

        $zone->write($full, is_string($body) ? $body : stream_get_contents($body), $headers);

        return ['zone' => $zoneCode, 'path' => $full, 'size' => $zone->fileSize($full)];
    }

    private function joinPath(string $path, string $filename): string
    {
        return ltrim(rtrim($path, '/') . '/' . $filename, '/');
    }

    /**
     * Resolve a zone code, defaulting to the sole configured zone when none is given.
     */
    private function resolveZoneCode(?string $code): string
    {
        if ($code !== null && $code !== '') {
            return $code;
        }

        $zones = $this->getZones();
        if (\count($zones) === 1) {
            return (string) array_key_first($zones);
        }

        throw new \InvalidArgumentException(sprintf(
            'A storage zone is required; %d zones are configured (%s).',
            \count($zones),
            implode(', ', array_keys($zones)),
        ));
    }
}
