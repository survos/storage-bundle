<?php

namespace Survos\StorageBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\StorageBundle\Entity\Storage;

/**
 * @extends ServiceEntityRepository<Storage>
 */
class StorageRepository extends EntityRepository
{
}
