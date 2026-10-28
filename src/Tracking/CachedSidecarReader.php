<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataContracts\Util\MediaKeyService;
use Survos\StorageBundle\Entity\StorageNode;

/** Local-only lookup. Null means unpopulated; callers may fall back to storage. */
final readonly class CachedSidecarReader
{
    public function __construct(private EntityManagerInterface $em, private TrackingConfig $config)
    {
    }

    public function read(string $zone, string $mediaId, string $task): ?array
    {
        if (!$this->config->enabled($zone)) {
            return null;
        }
        $path = MediaKeyService::archivePathFromKey($mediaId, $task.'.json', $this->config->prefix($zone));
        // Scalar query avoids stale identity-map state in long-running AI workers.
        $row = $this->em->createQueryBuilder()->select('n.meta')->from(StorageNode::class, 'n')
            ->where('n.id = :id')->setParameter('id', StorageNode::calcCode($zone, $path))->getQuery()->getOneOrNullResult();
        if (!isset($row['meta'][StorageNode::CONTENT])) {
            return null;
        }
        $result = json_decode($row['meta'][StorageNode::CONTENT], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new \UnexpectedValueException('AI sidecar must contain a JSON object or array.');
        }

        return $result;
    }
}
