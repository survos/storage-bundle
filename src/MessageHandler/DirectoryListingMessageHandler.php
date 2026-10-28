<?php

declare(strict_types=1);

namespace Survos\StorageBundle\MessageHandler;

use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\DirectoryScanStarter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Compatibility for queued directory messages when the native workflow is enabled. */
#[AsMessageHandler]
final readonly class DirectoryListingMessageHandler
{
    public function __construct(private DirectoryScanStarter $scanner)
    {
    }

    public function __invoke(DirectoryListingMessage $message): void
    {
        if (StorageNode::TYPE_DIR !== $message->type && DirectoryListingMessage::LOAD !== $message->type) {
            throw new \InvalidArgumentException('Expected a directory listing message.');
        }
        $this->scanner->start($message->zoneId, $message->path, 'sync' === $message->transport);
    }
}
