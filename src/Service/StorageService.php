<?php

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
        return $this->getprivateProperty($adapter, 'client');
    }

    public function getBucket(FilesystemAdapter $adapter): string
    {
        return $this->getprivateProperty($adapter, 'bucket');
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


    public function downloadFile(string $filename, string $path, ?string $storageZone = null)
    {
    }

    public function uploadFile(
        string  $fileName, // the filename on storage
        mixed   $body, // content to write
        ?string $storageZoneName = null,
        string  $path = '',
        array   $headers = [],
    ): array
    {
        // ??
        return [];

    }


}
