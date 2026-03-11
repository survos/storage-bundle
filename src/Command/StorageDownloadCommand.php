<?php

namespace Survos\StorageBundle\Command;

use Exception;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ZipArchive;

#[AsCommand('storage:download', 'download remote storage files')]
final class StorageDownloadCommand extends Command
{

    public function __construct(
        private readonly StorageService $storageService,
        #[Autowire('%kernel.project_dir%')] private $projectDir,
    ) {
        parent::__construct();
        $this->setHelp(
            <<<END
# downloads to data/composer.json
bin/console -vvv storage:download data/composer.json

END
        );
    }

    /**
     * @throws Exception
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('path within zone')] string $remoteFilename = '',
        #[Argument('local directory')] ?string $localDirOrFilename = '',
        #[Option('zone name', name: 'zone')] ?string $zoneName = null,
        #[Option('unzip')] ?bool $unzip = null,
        #[Option('download even if file exists')] bool $force = false,
    ): int {
        if ($unzip) {
            if (pathinfo($remoteFilename, PATHINFO_EXTENSION) !== 'zip') {
                $io->error("Only zip files are supported");
                return self::FAILURE;
            }
        }

        $shortDownloadFilename = pathinfo($remoteFilename, PATHINFO_BASENAME);

        $downloadDir = $this->sanitizeLocalDir(
            $remoteFilename,
            $localDirOrFilename
        );

        if (!str_ends_with($downloadDir, '/')) {
            $downloadDir .= '/';
        }

        $filename = (pathinfo($localDirOrFilename, PATHINFO_EXTENSION)) ? basename($localDirOrFilename) : null;

        if (!is_dir($downloadDir)) {
            $io->info("Creating " . $downloadDir);
            mkdir($downloadDir, 0777, true);
        }

        if (!str_ends_with($downloadDir, '/')) {
            $downloadDir .= "/";
        }

        if ($filename) {
            $downloadPath =  $downloadDir . $filename;
        } else {
            $downloadPath = $downloadDir . $shortDownloadFilename;
        }

        $downloadPath = $this->clearDirPath($downloadPath);
        if ($force || !file_exists($downloadPath)) {
            $remotePath = pathinfo($remoteFilename, PATHINFO_DIRNAME);
            $remoteShortName = pathinfo($remoteFilename, PATHINFO_BASENAME);

            $io->info("Downloading $remoteShortName at $remotePath to  $downloadPath");
//            dump(realPath: $downloadPath, filename: $shortDownloadFilename);


            $ret = $this->storageService->downloadFile($remoteShortName, $remotePath, $zoneName);
            file_put_contents($downloadPath, $ret->getContents());
            $size = filesize($downloadPath);
            $io->info("$downloadPath written with $size bytes");
        }

        $io->success($this->getName() . ': downloaded to ' . realpath($downloadPath));

        if ($unzip) {
            $io->info("Unzipped $downloadPath to $localDirOrFilename");
            $dir = $downloadDir . DIRECTORY_SEPARATOR . pathinfo($downloadPath, PATHINFO_FILENAME);
            $io->info("Unzipping $downloadPath to $dir");
            $this->unzip($downloadPath, $dir, $io);

            $table = new Table($io);
            $table->setStyle('compact');
            $table->setHeaders(['name', 'size']);
            foreach (glob($dir . '/*') as $file) {
                $table->addRow([basename($file), filesize($file)]);
            }
            $table->render();
        }

        return self::SUCCESS;
    }

    private function sanitizeLocalDir(
        string $remoteFilename,
        string $localDirOrFilename = ""
    ): string {
        if ($localDirOrFilename) {
            $shortFilename = pathinfo($localDirOrFilename, PATHINFO_BASENAME);
            if (str_ends_with($localDirOrFilename, '/') || !pathinfo($localDirOrFilename, PATHINFO_EXTENSION)) {
                $downloadFilename = $localDirOrFilename . $shortFilename;
                $downloadDir = $this->removeForwardSlash($localDirOrFilename);
            } else {
                // it's a filename
                $downloadDir = pathinfo($localDirOrFilename, PATHINFO_DIRNAME);
                if ($downloadDir === '.') {
                    $downloadDir = '';
                }
            }
        } else {
            $downloadDir = pathinfo($remoteFilename, PATHINFO_DIRNAME);
        }

        if (!str_starts_with($downloadDir, '/')) {
            // The string does not start with a '/', prepend $this->projectDir
            $downloadDir = $this->projectDir . '/' . $downloadDir;
        }
        return $downloadDir;
    }

    private function removeForwardSlash(string $path): string
    {
        if (str_ends_with($path, '/')) {
            return rtrim($path, '/');
        }
        return $path;
    }
    /**
     * @param string $zipPath
     * @param string $destination
     * @throws Exception
     */
    private function unzip(string $zipPath, string $destination, SymfonyStyle $io): void
    {
        $zip = new ZipArchive();
        try {
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($destination);
                $zip->close();
            }
        } catch (Exception $e) {
            $io->error($e->getMessage());
            throw new Exception("Could not unzip $zipPath to $destination");
        }
    }

    private function clearDirPath(string $fullFilePath): string
    {
        // Normalize the path by replacing multiple slashes with a single slash
        $normalizedPath = preg_replace('#/+#', '/', $fullFilePath);

        // If path starts with a single `/` (for absolute paths), retain it
        if ($fullFilePath[0] === '/') {
            $normalizedPath = '/' . ltrim($normalizedPath, '/');
        }
        return $normalizedPath;
    }
}
