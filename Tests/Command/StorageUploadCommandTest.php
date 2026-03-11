<?php
namespace Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Survos\StorageBundle\Command\StorageDownloadCommand;

class StorageUploadCommandTest extends KernelTestCase
{

    public function testExecute(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('storage:upload');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'filename' => 'composer.json',
            'remoteDirOrFilename' => 'test',
            '--zip' => false
        ]);

        $commandTester->assertCommandIsSuccessful();

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('storage:upload started', $output);
        $this->assertStringContainsString('Uploading composer.json to museado', $output);
        $this->assertStringContainsString('storage:upload finished', $output);
    }

    public function testUploadFolderWithoutZip(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('storage:upload');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'filename' => 'bin',
            'remoteDirOrFilename' => 'test',
            '--zip' => false
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Please specify --zip for directories', $output);
    }

    public function testUploadFolderWithZip(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('storage:upload');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'filename' => 'bin',
            'remoteDirOrFilename' => 'test',
            '--zip' => true
        ]);

        $commandTester->assertCommandIsSuccessful();

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('storage:upload started', $output);
        $this->assertStringContainsString('bin.zip to museado/testbin.zip', $output);
        $this->assertStringContainsString('storage:upload finished', $output);
    }
}
