<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Command;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[AsCommand('storage:iterate', 'Iterate storage directories; optionally dispatch an event or write JSONL.')]
final class IterateCommand
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Zone id, e.g. default.storage (prompted if ambiguous)')]
        ?string $zone = null,
        #[Option(description: 'Path within zone (default: root)')]
        string $path = '',
        #[Option(description: 'Dispatch a DirectoryListingMessage')]
        bool $dispatch = false,
        #[Option(description: 'Write a Flysystem-shaped JSONL manifest (sync). Filename defaults to <zone>[__<path>].jsonl')]
        bool $jsonl = false,
        #[Option(description: 'Override JSONL output filename')]
        ?string $out = null,
        #[Option(description: 'Include adapter extraMetadata in JSONL rows (S3 user metadata, ETag, etc.)')]
        bool $extraMeta = true,
    ): int {
        $zone = $this->resolveZone($io, $zone);
        if ($zone === null) {
            return Command::FAILURE;
        }

        if (!$dispatch && !$jsonl) {
            $io->warning('Pass --dispatch or --jsonl to do something besides validate the zone.');
            return Command::SUCCESS;
        }

        if ($dispatch) {
            $this->messageBus->dispatch(new DirectoryListingMessage(
                $zone,
                StorageNode::TYPE_DIR,
                $path,
                context: [DirectoryListingMessage::CONTEXT_VERBOSITY => $io->getVerbosity()],
            ));
            $io->success(sprintf('Dispatched DirectoryListingMessage: %s %s', $zone, $path === '' ? '/' : $path));
        }

        if ($jsonl) {
            $out ??= self::defaultJsonlFilename($zone, $path);
            return $this->writeJsonl($io, $zone, $path, $out, $extraMeta);
        }

        return Command::SUCCESS;
    }

    private static function defaultJsonlFilename(string $zone, string $path): string
    {
        $slugger = new AsciiSlugger();
        $base = (string) $slugger->slug($zone, '_')->lower();
        $trimmed = trim($path, '/');
        if ($trimmed !== '') {
            $base .= '__' . (string) $slugger->slug($trimmed, '_')->lower();
        }
        return $base . '.jsonl';
    }

    private function resolveZone(SymfonyStyle $io, ?string $zone): ?string
    {
        $zones = $this->storageService->getZones();
        $ids = array_keys($zones);

        if ($zone !== null) {
            if (!isset($zones[$zone])) {
                $io->error(sprintf('Unknown zone "%s". Available: %s', $zone, implode(', ', $ids)));
                return null;
            }
            return $zone;
        }

        $default = $this->storageService->getConfig()['default_zone'] ?? null;
        if ($default !== null && isset($zones[$default])) {
            return $default;
        }

        if (count($ids) === 1) {
            return $ids[0];
        }

        if ($ids === []) {
            $io->error('No storage zones configured.');
            return null;
        }

        return (string) $io->askQuestion(new ChoiceQuestion('Select storage zone', $ids));
    }

    private function writeJsonl(
        SymfonyStyle $io,
        string $zone,
        string $path,
        string $out,
        bool $extraMeta,
    ): int {
        $fs = $this->storageService->getZone($zone);

        $io->writeln(sprintf('Writing JSONL: %s:%s -> %s', $zone, $path === '' ? '/' : $path, $out));

        $writer = JsonlWriter::open($out);
        $seenDirs = [$path => true];
        $dirCount = 0;
        $fileCount = 0;
        $totalBytes = 0;

        $writer->write([
            'type' => 'dir',
            'path' => $path,
            'zone' => $zone,
            'id' => self::hashId('DIR:' . $path),
            'parent_id' => null,
            'root' => true,
        ]);
        $dirCount++;

        $emitDir = function (string $dirPath) use (&$seenDirs, &$dirCount, $writer, $zone, &$emitDir): void {
            if ($dirPath === '' || $dirPath === '.' || isset($seenDirs[$dirPath])) {
                return;
            }
            $parent = \dirname($dirPath);
            if ($parent !== '.' && $parent !== '/' && $parent !== '') {
                $emitDir($parent);
            }
            $seenDirs[$dirPath] = true;
            $writer->write([
                'type' => 'dir',
                'path' => $dirPath,
                'zone' => $zone,
                'id' => self::hashId('DIR:' . $dirPath),
                'parent_id' => ($parent !== '.' && $parent !== '' && $parent !== '/')
                    ? self::hashId('DIR:' . $parent)
                    : null,
            ]);
            $dirCount++;
        };

        try {
            /** @var iterable<FileAttributes|DirectoryAttributes> $iter */
            $iter = $fs->listContents($path, true);

            foreach ($iter as $attr) {
                $p = $attr->path();

                if ($attr instanceof DirectoryAttributes) {
                    $emitDir($p);
                    continue;
                }

                /** @var FileAttributes $attr */
                $parent = \dirname($p);
                if ($parent !== '.' && $parent !== '' && $parent !== '/') {
                    $emitDir($parent);
                }

                $row = [
                    'type' => 'file',
                    'path' => $p,
                    'zone' => $zone,
                    'id' => self::hashId('FILE:' . $p),
                    'parent_id' => ($parent !== '.' && $parent !== '' && $parent !== '/')
                        ? self::hashId('DIR:' . $parent)
                        : null,
                    'size' => $attr->fileSize(),
                    'last_modified' => $attr->lastModified(),
                    'mime_type' => $attr->mimeType(),
                    'visibility' => $attr->visibility(),
                ];
                if ($extraMeta) {
                    $extra = $attr->extraMetadata();
                    if ($extra !== []) {
                        $row['extra'] = $extra;
                    }
                }
                $writer->write($row);
                $fileCount++;
                $totalBytes += (int) ($attr->fileSize() ?? 0);
            }
        } catch (FilesystemException $e) {
            $writer->finish();
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $writer->finish();

        $io->definitionList(
            ['dirs' => (string) $dirCount],
            ['files' => (string) $fileCount],
            ['bytes' => (string) $totalBytes],
            ['output' => $out],
        );

        return Command::SUCCESS;
    }

    private static function hashId(string $v): string
    {
        return \in_array('xxh3', \hash_algos(), true) ? \hash('xxh3', $v) : \sha1($v);
    }
}
