<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Command;

use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('storage:iterate', 'iterate and dispatch an event though storage directories')]
final class IterateCommand
{

    public function __construct(
        private readonly StorageService $storageService,
        private MessageBusInterface $messageBus,
    ) {}

    public function __invoke(
        SymfonyStyle                                                                                          $io,
        #[Argument(description: 'path name within zone')] string        $path='',
        #[Argument(name: 'zone', description: 'zone id, e.g. default.storage')] string        $zoneId='',
        #[Option] ?bool $recursive = null,
        #[Option(description: "Dispatch a DirectoryListingMessage event")] bool $dispatch = false,

    ): int
    {
        if (!$dispatch) {
            $io->warning('use --dispatch to do something besides list');
        }
        $this->storageService->getZone($zoneId);

        if ($dispatch) {
            $message = new DirectoryListingMessage(
                $zoneId,
                StorageNode::TYPE_DIR,
                $path,
                context: [DirectoryListingMessage::CONTEXT_VERBOSITY => $io->getVerbosity()],
            );
            $this->messageBus->dispatch($message);
            $io->success("$zoneId $path dispatched");
        }
        return Command::SUCCESS;
    }

}
