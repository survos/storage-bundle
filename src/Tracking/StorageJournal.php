<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tracking;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use League\Flysystem\WhitespacePathNormalizer;
use Survos\StorageBundle\Entity\StorageChange;
use Survos\StorageBundle\Entity\StorageJournalHead;

/** Record only AFTER a backend write succeeds, including external Imagor writes. */
final readonly class StorageJournal
{
    public function __construct(private Connection $connection, private TrackingConfig $config)
    {
    }

    public function record(string $zone, string $path, string $operation = StorageChange::WRITE, array $revision = []): ?int
    {
        if (!$this->config->enabled($zone)) {
            return null;
        }
        if (!in_array($operation, [StorageChange::WRITE, StorageChange::DELETE, StorageChange::DELETE_DIRECTORY, StorageChange::DIRECTORY, StorageChange::METADATA], true)) {
            throw new \InvalidArgumentException('Unsupported storage operation: '.$operation);
        }
        $path = (new WhitespacePathNormalizer())->normalizePath($path);
        $db = $this->connection;
        if (false === $db->fetchOne('SELECT id FROM '.StorageJournalHead::TABLE.' WHERE id = 1')) {
            try {
                // A nested transaction provides a savepoint for a competing first writer.
                $db->transactional(fn () => $db->insert(StorageJournalHead::TABLE, ['id' => 1, 'sequence' => 0]));
            } catch (UniqueConstraintViolationException) {
                // Another writer initialized the journal.
            }
        }

        return $db->transactional(function () use ($db, $zone, $path, $operation, $revision): int {
            $db->executeStatement('UPDATE '.StorageJournalHead::TABLE.' SET sequence = sequence + 1 WHERE id = 1');
            $id = (int) $db->fetchOne('SELECT sequence FROM '.StorageJournalHead::TABLE.' WHERE id = 1');
            $db->insert(StorageChange::TABLE, [
                'id' => $id, 'zone' => $zone, 'path' => $path, 'operation' => $operation,
                'revision' => json_encode($revision, JSON_THROW_ON_ERROR),
                'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'processed_at' => null,
            ]);

            return $id;
        });
    }

    /** Stable, committed sequence cursor. Rows remain available after consumption. */
    public function since(int $after = 0, int $limit = 100): array
    {
        if ($after < 0 || $limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid changelog page.');
        }
        $rows = $this->connection->fetchAllAssociative('SELECT id, zone, path, operation, revision, recorded_at FROM '.StorageChange::TABLE.' WHERE id > ? ORDER BY id LIMIT '.$limit, [$after]);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['revision'] = json_decode($row['revision'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    public function pending(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid batch size.');
        }

        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM '.StorageChange::TABLE.' WHERE processed_at IS NULL ORDER BY id LIMIT '.$limit));
    }
}
