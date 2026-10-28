<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Command;

use Survos\StorageBundle\Service\DirectoryScanStarter;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('storage:populate', 'Populate StorageNodes; directories and files start through workflow Kickoff')]
final readonly class PopulateCommand
{
    public function __construct(private StorageService $storage, private DirectoryScanStarter $scanner)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Flysystem zone', name: 'zone')] ?string $zoneId = null,
        #[Argument('Directory within zone')] string $path = '/',
        #[Option('Compatibility option: new directories always dispatch after persistence')] bool $dispatch = false,
        #[Option('Compatibility option: traversal always discovers child directories')] bool $recursive = false,
        #[Option('Fetch all discovered directories in this process')] bool $sync = false,
    ): int {
        $zoneId ??= $io->askQuestion(new ChoiceQuestion('Storage zone', \array_keys($this->storage->getZones())));
        $node = $this->scanner->start($zoneId, $path, $sync);
        $io->success($sync
            ? 'Storage nodes loaded synchronously.'
            : 'Root queued; consume storage.fetch to list directories and load files.');
        $io->definitionList(['path' => $node->path], ['status' => $node->status]);

        return Command::SUCCESS;
    }
}
