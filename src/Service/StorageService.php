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
        private array $zoneMap = [], // maps code to zone index
        /** @var array<string, array<string, mixed>> declared adapter config, keyed by zone code */
        private array $zoneConfig = []
    )
    {
    }

    /**
     * The one reflection this class cannot avoid: League\Flysystem\Filesystem keeps its adapter
     * private and offers no accessor, so there is no supported way to reach it. Everything the
     * zones UI needs ABOUT an adapter now comes from the declared flysystem config instead (see
     * SurvosStorageBundle::process()); this remains only for callers that need the adapter object.
     */
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
            $code = $this->zoneMap[$idx];
            $config = $this->zoneConfig[$code] ?? [];

            // The class still comes from the built adapter: it is the one thing the declared
            // config does not name (flysystem.yaml says "aws", not AwsS3V3Adapter::class), and
            // reading ::class off an object is not reflection into internals.
            $flysystemAdapter = $this->getPrivateProperty($flysystem, 'adapter');

            $adapter = new Adapter(
                $code,
                $flysystemAdapter ? $flysystemAdapter::class : '',
                rootLocation: $config['directory'] ?? null,
                bucket: $config['bucket'] ?? null,
                prefix: $config['prefix'] ?? null,
            );


            $adapters[$code] = $adapter;
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



    /**
     * Called once per flysystem storage by SurvosStorageBundle::process(). $config is the
     * DECLARED adapter config for that storage (adapter type, bucket, prefix, directory) taken
     * from the app's flysystem.yaml — see the note there on why that beats reflecting into the
     * built adapter.
     *
     * @param array<string, mixed> $config
     */
    public function addAdapter(string $code, int $index, array $config = []): void
    {
        $this->zoneMap[$index] = $code;
        $this->zoneConfig[$code] = $config;
    }
    private function getPrivateProperty(mixed $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        if (!$reflection->hasProperty($property)) {
            return null;
        }

        $reflectionProperty = $reflection->getProperty($property);

        // isInitialized() is not optional. Reading an uninitialized TYPED property is a fatal
        // Error, not a null -- and adapters that connect lazily leave theirs unset until first
        // use. FtpAdapter is the live example: listing the zones page threw
        // "Typed property FtpAdapter::$prefixer must not be accessed before initialization"
        // purely from asking what its prefix was, without ever touching the FTP server.
        if (!$reflectionProperty->isInitialized($object)) {
            return null;
        }

        return $reflectionProperty->getValue($object);
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
