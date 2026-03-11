<?php

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
