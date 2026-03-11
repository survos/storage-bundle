<?php

namespace Survos\StorageBundle\Command;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Zenstruck\Bytes;

#[AsCommand('storage:list', 'list storage files')]
final class StorageListCommand extends Command
{

    public function __construct(
        private readonly StorageService $storageService,
        private EventDispatcherInterface $eventDispatcher,
        private ?Stopwatch $stopwatch=null,
    )
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle                                                                                          $io,
        #[Argument('path name within zone')] string        $path='',
        #[Argument('zone id, e.g. default.storage', name: 'zone')] ?string $zoneId=null,
//        #[Option] ?bool $recursive = null,
        #[Option] int $dirLimit = 10,
        #[Option] int $fileLimit = 20,

    ): int
    {
        $recursive = false; // too expensive!
        if (!$zoneId) {
            $this->renderStorages($io);
            $zoneChoices = [];
            foreach ($this->storageService->getZones() as $storageId => $zone) {
                $adapter = $this->storageService->getAdapter($storageId);
                $model = $this->storageService->getAdapterModel($storageId);
                $zoneChoices[] = $storageId; // sprintf('%s %s (%s)', $zoneId, pathinfo($adapter::class), $model->bucket);
            }
            $zoneId = $io->askQuestion(new ChoiceQuestion("storage key (from flysytem)", $zoneChoices));
        }
        // this is a Flysystem/Filesystem class, which flysystem calls "storage"
        $storage = $this->storageService->getZone($zoneId);
        // @todo: add a timer here.
        if ($stopwatch = $this->stopwatch) {
            $stopwatch->start('listing');
            $iterator = $storage->listContents($path, (bool)$recursive);
            $event = $stopwatch->stop('listing');
            $msg = sprintf(
                "Listing took %d ms and used %d MB of memory\n",
                $event->getDuration(),
                $event->getMemory() / 1024 / 1024
            );
        } else {
            $iterator = $storage->listContents('some/path', true);
            $msg = "No stopwatch";
        }
        $io->writeln($msg);

        // pagination?  dirs only?
        $files = ['dir' => []];
        foreach ($iterator as $file) {
            $type = $file['type'];
            $files[$type][] = $file;
        }
        $io->title(count($files['dir']) . ' directories');
        $table = new Table($io);
        $table->setHeaders(['path','modified','visibility']);
        /** @var DirectoryAttributes $file */
        foreach ($files['dir'] as $idx => $file) {
            if ($idx < $dirLimit) {
                $table->addRow([$file->path(), $file->lastModified(), $file->visibility()]);
            }
        }
        $table->render();

        $files = $files['file']??[];
        $io->title(count($files) . ' files');
        $table = new Table($io);
        $table->setHeaders(['path','size', 'modified','visibility']);
        /** @var FileAttributes $file */
        foreach ($files as $idx => $file) {
            if ($idx > $fileLimit) {
                break;
            }
            $table->addRow([$file->path(), Bytes::parse($file->fileSize()), $file->lastModified(), $file->visibility()]);
        }
        $table->render();

        dd();
        dump($files);
        $io->title($zoneId . ' has ' . count($files) . ' files/directories');
        $table->setHeaders(['name', 'type', 'storage', 'zone']);
        foreach ($files['dir'] as $dir) {

        }
//        foreach ($iterator as $file) {
//            dd($file);
//        }

        return self::SUCCESS;
    }

    private function renderStorages(SymfonyStyle $io)
    {
        $adapters = $this->storageService->getAdapters();
        $table = new Table($io);
        $table->setHeaderTitle("Flysystem Storage Adapters");
        $headers = ['Name', 'Class','root'];
        $table->setHeaders($headers);
        foreach ($adapters as $adapter) {
            $row = [
                $adapter->getName(),
                $adapter->getClass(),
                $adapter->getRootLocation()??$adapter->getBucket(),
            ];
//            $row['Id'] = "<href=https://dash.storage.net/storage/$id/file-manager>$id</>";

            $table->addRow($row);
        }
        $table->render();

    }




}
