<?php

namespace Survos\StorageBundle\Command;

use League\Flysystem\DirectoryAttributes;
use Survos\StorageBundle\Event\DirectoryListingEvent;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Bytes;

#[AsCommand('storage:iterate', 'iterate and dispatch an event though storage directories')]
final class IterateCommand extends Command
{

    public function __construct(
        private readonly StorageService $storageService,
        private MessageBusInterface $messageBus,
    )
    {
        parent::__construct();
    }

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
        $table = new Table($io);
        $storage = $this->storageService->getZone($zoneId);

        if ($dispatch) {
            $message = new DirectoryListingMessage($zoneId, 'dir', $path);
            $this->messageBus->dispatch($message);
            $io->success("$zoneId $path dispatched");
        }
        return self::SUCCESS;
    }

}
