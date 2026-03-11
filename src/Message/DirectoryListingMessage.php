<?php

namespace Survos\StorageBundle\Message;

class DirectoryListingMessage
{

    public const string PRE_ITERATE='pre_load';
    public const string LOAD='load';
    public const string POST_LOAD='post_load';
    public const string DISCARD='discard'; // do not import the row

    public function __construct(
        public string          $zoneId,
        public string          $type,
        public string          $path,
        public ?string          $visibility=null,
        public ?int          $lastModified=null,
        public array           $context = []
    )
    {
    }

}
