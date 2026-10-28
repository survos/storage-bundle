<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Updating this row serializes sequence allocation through transaction commit. */
#[ORM\Entity]
#[ORM\Table(name: 'storage_journal_head')]
class StorageJournalHead
{
    public const TABLE = 'storage_journal_head';
    #[ORM\Id, ORM\Column]
    public int $id = 1;
    #[ORM\Column(type: 'bigint')]
    public int $sequence = 0;
}
