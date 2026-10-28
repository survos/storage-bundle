<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

final readonly class TrackingConfig
{
    public function __construct(private array $storages = [])
    {
    }

    public function enabled(string $zone): bool
    {
        return $this->storages[$zone]['track'] ?? false;
    }

    public function prefix(string $zone): string
    {
        return $this->storages[$zone]['sidecar_prefix'];
    }

    public function cacheContents(string $zone, string $path): bool
    {
        foreach ($this->storages[$zone]['content_patterns'] ?? [] as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public function zones(): array
    {
        return array_keys(array_filter($this->storages, fn (array $c) => $c['track']));
    }
}
