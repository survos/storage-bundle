<?php

namespace Survos\StorageBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\StorageBundle\Repository\StorageRepository;
use function Symfony\Component\String\u;
#[ORM\Entity(repositoryClass: StorageRepository::class)]
#[ORM\Table(name: 'storage_storage')]
class Storage implements \Stringable
{
    #[ORM\Column(length: 255, nullable: true)]
    private(set) ?string $adapter = null;

    /**
     * @var Collection<int, StorageNode>
     */
    #[ORM\OneToMany(targetEntity: StorageNode::class, mappedBy: 'storage', orphanRemoval: true)]
    private Collection $storageNodes;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $root = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private(set) ?string $id = null
    )
    {
        $this->storageNodes = new ArrayCollection();
    }

    static public function calcCode(string $zoneId): string {
        return $zoneId; // str_replace('.', '-', $zoneId);
    }

    public function addStorageNode(StorageNode $storageNode): static
    {
        if (!$this->storageNodes->contains($storageNode)) {
            $this->storageNodes->add($storageNode);
            $storageNode->storage = $this;
        }

        return $this;
    }

    public function removeStorageNode(StorageNode $storageNode): static
    {
        if ($this->storageNodes->removeElement($storageNode)) {
            // set the owning side to null (unless already changed)
            if ($storageNode->storage === $this) {
                $storageNode->storage = $this;
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string)$this->id;
    }
}
