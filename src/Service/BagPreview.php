<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Service;

use Aws\S3\S3ClientInterface;
use League\Flysystem\FilesystemOperator;
use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\S3Configuration;
use Survos\DuckDbBundle\SchemaInspector;

/** Bounded tag previews. Displaying a manifest does not claim payload verification. */
final readonly class BagPreview
{
    private const int MAX_TAG_BYTES = 1048576;

    public function __construct(private StorageService $storages)
    {
    }

    public function inspect(string $zone, string $path): ?array
    {
        $storage = $this->storages->getZone($zone);
        $path = (new \League\Flysystem\WhitespacePathNormalizer())->normalizePath($path);
        if (\str_ends_with($path, '.tar') || \str_ends_with($path, '.gbag')) {
            return $this->archive($zone, $path);
        }
        if (!$storage->fileExists($path.'/bagit.txt')) {
            return null;
        }
        $tags = [];
        $payloads = [];
        foreach ($storage->listContents($path, false) as $file) {
            $name = \basename($file->path());
            if (!$file->isFile() || !(\in_array($name, ['bagit.txt', 'bag-info.txt', 'fetch.txt'], true) || \preg_match('/^(?:tag)?manifest-[a-zA-Z0-9-]+\.txt$/D', $name))) {
                continue;
            }
            if (\count($tags) >= 20) {
                break;
            }
            $text = $this->text($storage, $file->path());
            $tags[] = ['name' => $name, ...$text];
            if (\str_starts_with($name, 'manifest-')) {
                foreach (\explode("\n", $text['content']) as $line) {
                    if (\preg_match('/^[a-fA-F0-9]+[ \t]+(data\/[^\r\n]+)$/D', $line, $match)) {
                        $relative = $match[1];
                        if (!\in_array('..', \explode('/', $relative), true) && !\str_contains($relative, '\\')) {
                            $payloads[$relative] = $path.'/'.$relative;
                        }
                    }
                }
            }
        }
        \usort($tags, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return ['path' => $path, 'tags' => $tags, 'payloads' => \array_slice($payloads, 0, 200, true), 'truncated' => \count($payloads) > 200];
    }

    /** Uncompressed TAR bags: range-read headers/tags, skip payload bytes without downloading them. */
    private function archive(string $zone, string $path): ?array
    {
        $storage = $this->storages->getZone($zone);
        if (!$storage->fileExists($path)) {
            return null;
        }
        $size = $storage->fileSize($path);
        $offset = 0;
        $tags = [];
        $found = false;
        for ($entries = 0; $entries < 64 && $offset + 512 <= $size; ++$entries) {
            $header = $this->range($zone, $path, $offset, 512);
            if (512 !== \strlen($header) || str_repeat("\0", 512) === $header) {
                break;
            }
            // Reject non-TAR objects and corrupt headers before trusting the advertised size.
            $checksum = octdec(\trim(\substr($header, 148, 8), " \0"));
            $check = substr_replace($header, str_repeat(' ', 8), 148, 8);
            if (array_sum(unpack('C*', $check)) !== $checksum) {
                return null;
            }
            $bytes = octdec(\trim(\substr($header, 124, 12), " \0"));
            if ($bytes < 0 || $offset + 512 + $bytes > $size) {
                throw new \RuntimeException('Invalid TAR entry size.');
            }
            $name = \rtrim(\substr($header, 0, 100), "\0");
            $base = \basename($name);
            if (\in_array($header[156], ["\0", '0'], true) && (\in_array($base, ['bagit.txt', 'bag-info.txt', 'fetch.txt'], true) || \preg_match('/^(?:tag)?manifest-[a-zA-Z0-9-]+\.txt$/D', $base))) {
                $content = $this->range($zone, $path, $offset + 512, min($bytes, self::MAX_TAG_BYTES));
                $tags[] = ['name' => $base, 'content' => $content, 'truncated' => $bytes > self::MAX_TAG_BYTES];
                $found = $found || 'bagit.txt' === $base;
            }
            $offset += 512 + (int) ceil($bytes / 512) * 512;
        }

        return $found ? ['path' => $path, 'tags' => $tags, 'payloads' => [], 'truncated' => false, 'archive' => true, 'scanLimited' => $entries >= 64] : null;
    }

    private function range(string $zone, string $path, int $offset, int $length): string
    {
        if (0 === $length) {
            return '';
        }
        $model = $this->storages->getAdapterModel($zone);
        if (null === $model->rootLocation) {
            $adapter = $this->storages->getAdapter($zone);
            $client = $this->storages->getClient($adapter);
            if ($client instanceof S3ClientInterface) {
                $prefix = \trim($model->prefix ?? '', '/');
                $key = ('' === $prefix ? '' : $prefix.'/').$path;

                return (string) $client->getObject(['Bucket' => $this->storages->getBucket($adapter), 'Key' => $key, 'Range' => 'bytes='.$offset.'-'.($offset + $length - 1)])['Body'];
            }
        }
        $stream = $this->storages->getZone($zone)->readStream($path);
        try {
            if (0 !== fseek($stream, $offset)) {
                throw new \RuntimeException('Archive tag preview requires seekable local or S3 storage.');
            }
            $result = \stream_get_contents($stream, $length);
            if (false === $result) {
                throw new \RuntimeException('Cannot read archive tag.');
            }

            return $result;
        } finally {
            \fclose($stream);
        }
    }

    public function text(FilesystemOperator $storage, string $path): array
    {
        $stream = $storage->readStream($path);
        try {
            $text = \stream_get_contents($stream, self::MAX_TAG_BYTES + 1);
        } finally {
            \fclose($stream);
        }
        if (false === $text) {
            throw new \RuntimeException('Cannot read bag tag.');
        }

        return ['content' => \substr($text, 0, self::MAX_TAG_BYTES), 'truncated' => \strlen($text) > self::MAX_TAG_BYTES];
    }

    /** Opens only database metadata; no SELECT over payload records and no whole-file download. */
    public function schema(string $zone, string $path): array
    {
        $path = (new \League\Flysystem\WhitespacePathNormalizer())->normalizePath($path);
        if (!\class_exists(ConnectionFactory::class) || !\in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            return ['error' => 'Schema preview requires survos/duckdb-bundle and the native ext-pdo_duckdb driver.'];
        }
        if (!(\str_ends_with($path, '.duckdb') || \str_ends_with($path, '.dfolio'))) {
            throw new \InvalidArgumentException('Schema preview requires a DuckDB file.');
        }
        $model = $this->storages->getAdapterModel($zone);
        $factory = new ConnectionFactory();
        if (null !== $model->rootLocation) {
            $db = $factory->open(\rtrim($model->rootLocation, '/').'/'.\ltrim($path, '/'));
        } else {
            $adapter = $this->storages->getAdapter($zone);
            $client = $this->storages->getClient($adapter);
            if (!$client instanceof S3ClientInterface) {
                return ['error' => 'Schema preview is available for local and S3 storage.'];
            }
            $credentials = $client->getCredentials()->wait();
            $s3 = new S3Configuration((string) $client->getEndpoint(), $client->getRegion(),
                $client->getConfig('use_path_style_endpoint') ? 'path' : 'vhost',
                $credentials->getAccessKeyId(), $credentials->getSecretKey(), $credentials->getSecurityToken());
            $prefix = \trim($model->prefix ?? '', '/');
            $key = ('' === $prefix ? '' : $prefix.'/').\ltrim($path, '/');
            $db = $factory->open('s3://'.$this->storages->getBucket($adapter).'/'.$key, $s3);
        }

        return (new SchemaInspector())->inspect($db);
    }
}
