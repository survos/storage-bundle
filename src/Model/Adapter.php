<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Model;

class Adapter
{

    /**
     * @param string $name
     * @param string $class
     * @param string|null $rootLocation
     * @param string|null $bucket
     */
    public function __construct(
        private(set) string  $name,
        private(set) string  $class,
        private(set) ?string $rootLocation = null,
        private(set) ?string $bucket = null
    )
    {
    }

    /**
     * Short slug for the underlying Flysystem adapter: local, s3, sftp, ftp, zip, memory...
     *
     * Matched on the class name rather than instanceof, deliberately: the adapter packages are
     * all optional, so referencing e.g. AwsS3V3Adapter::class here would fatal in an app that
     * only installs the local adapter. A string match costs nothing and cannot break a container.
     */
    public string $type {
        get {
            $class = $this->class;

            return match (true) {
                str_contains($class, 'AsyncAwsS3'), str_contains($class, 'AwsS3') => 's3',
                str_contains($class, 'GoogleCloud')                               => 'gcs',
                str_contains($class, 'AzureBlob')                                 => 'azure',
                // Sftp before Ftp: "SftpAdapter" contains "ftp" case-insensitively, and getting
                // this order wrong labels every SFTP zone as plain FTP.
                str_contains($class, 'Sftp'), str_contains($class, 'PhpseclibV3') => 'sftp',
                str_contains($class, 'Ftp')                                       => 'ftp',
                str_contains($class, 'ZipArchive')                                => 'zip',
                str_contains($class, 'InMemory')                                  => 'memory',
                str_contains($class, 'Local')                                     => 'local',
                default                                                           => 'unknown',
            };
        }
    }

    /** Tabler icon name for {@see $type}; apps without ux-icons fall back to the type label. */
    public string $icon {
        get {
            return match ($this->type) {
                's3', 'gcs', 'azure' => 'tabler:cloud',
                'sftp'               => 'tabler:lock',
                'ftp'                => 'tabler:server',
                'zip'                => 'tabler:file-zip',
                'memory'             => 'tabler:cpu',
                'local'              => 'tabler:folder',
                default              => 'tabler:database',
            };
        }
    }

    /** Where the zone actually points — the bucket for remote adapters, the path for local ones. */
    public ?string $location {
        get => $this->bucket ?: $this->rootLocation;
    }

    public function getAbsolutePath(string $path): string
    {
        // only true if local!  Better is to use the underlying adapter and has
        $absolutePath =  $this->rootLocation.'/'.$path;
//        dd($absolutePath, $this->rootLocation, file_exists($absolutePath));
        return $absolutePath;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getRootLocation(): ?string
    {
        return $this->rootLocation;
    }

    public function getBucket(): ?string
    {
        return $this->bucket;
    }

}
