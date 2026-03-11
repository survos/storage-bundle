<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\DirectoryAttributes;
use Psr\Log\LoggerInterface;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Repository\StorageNodeRepository;
use Survos\StorageBundle\Repository\StorageRepository;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand('storage:populate', 'Populate the storageNode database from a flysystem storage adapter')]
final class PopulateCommand
{

    private StorageNodeRepository $storageNodeRepository;
    private StorageRepository $storageRepository;
    public function __construct(
        private readonly StorageService $storageService,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    )
    {
        $this->storageNodeRepository = $entityManager->getRepository(StorageNode::class);
        $this->storageRepository = $entityManager->getRepository(Storage::class);
    }

    public function __invoke(
        SymfonyStyle                                                                                          $io,
        #[Argument(description: 'zone id, e.g. local.storage', name: 'zone')] ?string $zoneId=null,
        #[Argument('path name within zone')] string        $path='/',
        #[Option] bool $recursive = false,
        #[Option("Dispatch an LoadDirectory message")] bool $dispatch = false,
        #[Option] ?bool $sync = null,

    ): int
    {
        if (!$zoneId) {
            $zoneChoices = [];
            foreach ($this->storageService->getZones() as $storageId => $zone) {
                $adapter = $this->storageService->getAdapter($storageId);
                $model = $this->storageService->getAdapterModel($storageId);
                $zoneChoices[] = $storageId; // sprintf('%s %s (%s)', $zoneId, pathinfo($adapter::class), $model->bucket);
            }
            $zoneId = $io->askQuestion(new ChoiceQuestion("storage key (from flysytem)", $zoneChoices));
        }
        // this is a Flysystem/Filesystem class, which flysystem calls "storage"
        $zone = $this->storageService->getZone($zoneId);

        if (!$dispatch) {
            $io->warning('use --dispatch to do something besides populate database');
        }
//        $storage = $this->storageService->getZone($zoneId);

        $path = ltrim($path, '/');
//        $adapter = $this->storageService->getAdapter($zoneId);
//        $zone = $this->storageService->getZone($zoneId);
        $storageCode = Storage::calcCode($zoneId);
        if (!$storage = $this->storageRepository->find($storageCode)) {
//            assert(false, "missing a storage entity??");
            $storage = new Storage($storageCode);
            $this->entityManager->persist($storage);
        }

        $code = StorageNode::calcCode($storage, $path);
        // @todo: fetch codes for faster skipping
        if (!$dirEntity = $this->storageNodeRepository->find($code)) {
            $dirEntity = new StorageNode($storage, $path, isDir: true, isPublic: true);
            $dirEntity->name = $zoneId . ' Root';
            assert($code == $dirEntity->id);
            $this->entityManager->persist($dirEntity);
        }
        $this->entityManager->flush();
        $io->writeln("File and Storage entities written");

        $dispatched = [];
        if ($dispatch) {
            foreach ($this->storageNodeRepository->findBy([
                'status' => StorageNode::STATUS_NEW_DIR,
                'storage' => $storage,
            ]) as $node) {
                $stamps = [];
                $message = new DirectoryListingMessage($zoneId, $node->type, $node->path);
                if ($sync) {
                    $stamps[] = new TransportNamesStamp(['sync']);
                }
                $this->logger->warning(sprintf('Dispatching %s:%s', $storage->id, $node->path));
                $this->messageBus->dispatch($message, $stamps);
                $dispatched[$node->id] = true;
            }
        }

        // Summary table for root children
        $table = new Table($io);
        $table->setHeaders(['Path', 'Status', 'Dirs', 'Files', 'Children', 'Dispatched']);

        foreach ($this->storageNodeRepository->findBy([
            'storage' => $storage,
            'parent' => $dirEntity,
        ]) as $child) {
            $table->addRow([
                $child->path,
                $child->status,
                $child->dirCount,
                $child->fileCount,
                $child->listingCount,
                isset($dispatched[$child->id]) ? 'yes' : '',
            ]);
        }

        $table->render();

        $io->success('Make sure bin/console mess:consume async is running');

        return Command::SUCCESS;

    }

}
