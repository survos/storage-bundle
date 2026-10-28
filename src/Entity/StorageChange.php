<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable journal, independent of disposable StorageNode records. */
#[ORM\Entity]
#[ORM\Table(name: 'storage_change')]
#[ORM\Index(columns: ['processed_at', 'id'], name: 'storage_change_pending')]
class StorageChange
{
    public const TABLE = 'storage_change';
    public const WRITE = 'write';
    public const DELETE = 'delete';
    public const DELETE_DIRECTORY = 'delete_directory';
    public const DIRECTORY = 'directory';
    public const METADATA = 'metadata';

    #[ORM\Id, ORM\Column(type: 'bigint')]
    public int $id;
    #[ORM\Column(length: 255)]
    public string $zone;
    #[ORM\Column(type: 'text')]
    public string $path;
    #[ORM\Column(length: 32)]
    public string $operation;
    #[ORM\Column(type: 'json')]
    public array $revision = [];
    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $recordedAt;
    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $processedAt = null;
}
