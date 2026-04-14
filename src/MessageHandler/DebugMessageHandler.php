<?php

declare(strict_types=1);

namespace Survos\StorageBundle\MessageHandler;

use Psr\Log\LoggerInterface;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use function basename;
use function count;
use function explode;
use function is_int;
use function sprintf;
use function str_repeat;
use function trim;

#[AsMessageHandler]
final class DebugMessageHandler
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public function __invoke(DirectoryListingMessage $message): void
    {
        if (!$this->debug) {
            return;
        }

        if ($message->type !== StorageNode::TYPE_DIR && $message->type !== DirectoryListingMessage::LOAD) {
            return;
        }

        $verbosity = is_int($message->context[DirectoryListingMessage::CONTEXT_VERBOSITY] ?? null)
            ? $message->context[DirectoryListingMessage::CONTEXT_VERBOSITY]
            : OutputInterface::VERBOSITY_NORMAL;

        if ($verbosity < OutputInterface::VERBOSITY_VERBOSE) {
            return;
        }

        $path = trim($message->path, '/');
        $depth = $path === '' ? 0 : count(explode('/', $path));
        $indent = str_repeat('  ', $depth);
        $label = $path === '' ? $message->zoneId : basename($path);

        $this->logger->info(sprintf('%s%s/', $indent, $label));

        if ($verbosity < OutputInterface::VERBOSITY_VERY_VERBOSE) {
            return;
        }

        $filesystem = $this->storageService->getZone($message->zoneId);

        foreach ($filesystem->listContents($path, false) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $this->logger->info(sprintf(
                '%s  %s (%d)',
                $indent,
                basename($item->path()),
                $item->fileSize() ?? 0,
            ));
        }
    }
}
