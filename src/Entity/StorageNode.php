<?php

namespace Survos\StorageBundle\Entity;
use Survos\StorageBundle\Repository\StorageNodeRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: StorageNodeRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_STORAGE_STORAGE_PATH', fields: ['storage', 'path'])]
//#[MeiliIndex(
//    persisted: new Fields(
//        fields: ['id','fileSize', 'listingCount', 'dirCount', 'fileCount', 'type'],
//        groups: ['file.read','minimum','search']
//    ),
//    sortable: ['listingCount','dirCount','fileCount'],
//    filterable: ['fileSize','listingCount','type']
//)]
class StorageNode implements \Stringable
{
    public const STATUS_NEW_DIR = 'new_dir';
    public const STATUS_NEW_FILE = 'new_file';
    public const STATUS_DIR_LOADED = 'dir_loaded'; // directories and files have been loaded.  All or nothing.

    public const TYPE_DIR='dir';
    public const TYPE_FILE='file';

    #[ORM\Id]
    #[ORM\Column()]
    #[Groups(['minimum', 'search', 'jstree'])]
    private(set) string $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['minimum', 'search', 'jstree'])]
    public string $name;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?\DateTimeInterface $lastModified = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    public ?int $fileSize = null;

    #[Groups(['file.read'])]
    public int $listingCount { get => $this->dirCount + $this->fileCount; }

    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    public ?int $dirCount = null;
    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    public ?int $fileCount = null;

    #[Groups(['file.read'])]
    public string $type { get => $this->isDir ? self::TYPE_DIR : self::TYPE_FILE; }
    #[Groups(['file.read'])]
    public ?string $ext { get => $this->isDir ? null : pathinfo($this->name, PATHINFO_EXTENSION); }



    #[Groups(['file.read'])]
    #[ORM\Column(nullable: false)]
    public string $status;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE', nullable: true)]
    public ?StorageNode $parent = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $meta = null;

    public ?float $duration { get => $this->meta['duration'] ?? null; }



//    private $children;
    public function __construct(

        #[ORM\ManyToOne(inversedBy: 'storageNodes')]
        #[ORM\JoinColumn(nullable: false)]
        public ?Storage $storage = null,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        #[Groups(['file.read'])]
        private(set) ?string  $path = null,
        #[ORM\Column(type: 'boolean')]
        #[Groups(['file.read'])]
        private(set) bool $isDir = false,
        #[ORM\Column(type: 'boolean')]
        #[Groups(['file.read'])]
        public bool $isPublic = true,
    )
    {
        if ($storage) {
            // no!
//            $this->storage->addStorage($this);
        }
        $this->status = $this->isDir ? self::STATUS_NEW_DIR : self::STATUS_NEW_FILE;
        // unfortunately, these codes are different than the filenames!
        $this->id = self::calcCode($this->storageId, $this->path);
        if ($this->isDir) {
            $this->dirCount = 0;
            $this->fileCount = 0;
        }
    }

    static public function calcCode(string|Storage $storageId, string $path): string
    {
        return hash('xxh3', (is_string($storageId) ? $storageId : $storageId->id) . $path);
    }

    public string $storageId { get => $this->storage->id; }


    public function __toString(): string
    {
        return (string)$this->name;
    }
}
