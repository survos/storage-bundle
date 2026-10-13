<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Survos\StorageBundle\Entity\StorageNode;

/**
 * @method StorageNode|null find($id, $lockMode = null, $lockVersion = null)
 * @method StorageNode|null findOneBy(array $criteria, array $orderBy = null)
 * @method StorageNode[]    findAll()
 * @method StorageNode[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StorageNodeRepository extends EntityRepository
{
}
