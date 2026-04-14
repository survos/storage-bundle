<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Message;

final class DirectoryListingMessage
{
    public const string CONTEXT_VERBOSITY = 'verbosity';

    public const string PRE_ITERATE='pre_load';
    public const string LOAD='load';
    public const string POST_LOAD='post_load';
    public const string DISCARD='discard'; // do not import the row

    public function __construct(
        public string   $zoneId,
        public string   $type,
        public string   $path,
        /** Basename of the path (empty string for zone root) */
        public string   $name = '',
        /** Parent directory path (empty string for top-level items) */
        public string   $parentPath = '',
        /** Depth within the zone: root=0, top-level items=1, etc. */
        public int      $depth = 0,
        public ?string  $visibility = null,
        public ?int     $lastModified = null,
        /** Byte size — populated for file-type messages */
        public ?int     $fileSize = null,
        /** MIME type — populated for file-type messages when available */
        public ?string  $mimeType = null,
        /** Total direct children — populated on POST_LOAD messages */
        public ?int     $totalChildren = null,
        public array    $context = [],
    )
    {
    }
}
