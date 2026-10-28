<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use Survos\StorageBundle\Entity\StorageChange;

final readonly class TrackedAdapter implements FilesystemAdapter, \League\Flysystem\UrlGeneration\PublicUrlGenerator, \League\Flysystem\UrlGeneration\TemporaryUrlGenerator
{
    public function __construct(public FilesystemAdapter $inner, private StorageJournal $journal, private string $zone)
    {
    }

    public function publicUrl(string $path, Config $config): string
    {
        if (!$this->inner instanceof \League\Flysystem\UrlGeneration\PublicUrlGenerator) {
            throw \League\Flysystem\UnableToGeneratePublicUrl::noGeneratorConfigured($path);
        }

        return $this->inner->publicUrl($path, $config);
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
    {
        if (!$this->inner instanceof \League\Flysystem\UrlGeneration\TemporaryUrlGenerator) {
            throw \League\Flysystem\UnableToGenerateTemporaryUrl::noGeneratorConfigured($path);
        }

        return $this->inner->temporaryUrl($path, $expiresAt, $config);
    }

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function read(string $path): string
    {
        return $this->inner->read($path);
    }

    public function readStream(string $path)
    {
        return $this->inner->readStream($path);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->inner->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->inner->write($path, $contents, $config);
        $this->journal->record($this->zone, $path, revision: [SidecarCache::SHA256 => hash('sha256', $contents)]);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->inner->writeStream($path, $contents, $config);
        $this->journal->record($this->zone, $path);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
        $this->journal->record($this->zone, $path, StorageChange::DELETE);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
        $this->journal->record($this->zone, $path, StorageChange::DELETE_DIRECTORY);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
        $this->journal->record($this->zone, $path, StorageChange::DIRECTORY);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
        $this->journal->record($this->zone, $path, StorageChange::METADATA);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
        $this->journal->record($this->zone, $source, StorageChange::DELETE);
        $this->journal->record($this->zone, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
        $this->journal->record($this->zone, $destination);
    }
}
