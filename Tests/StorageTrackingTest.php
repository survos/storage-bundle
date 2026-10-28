<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tests;

require_once __DIR__.'/NativeDirectoryTest.php';

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Util\MediaKeyService;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageChange;
use Survos\StorageBundle\Entity\StorageJournalHead;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Service\StorageService;
use Survos\StorageBundle\Tracking\StorageChangeConsumer;
use Survos\StorageBundle\Tracking\StorageJournal;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class TrackingFixtureKernel extends StorageFixtureKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->extension('survos_storage', ['storages' => ['fixture.storage' => ['track' => true, 'content_patterns' => ['o/*.json']]]]);
    }
}

#[CoversNothing]
final class StorageTrackingTest extends TestCase
{
    private TrackingFixtureKernel $kernel;
    private EntityManagerInterface $em;
    private string $root;
    private Filesystem $fs;
    private StorageJournal $journal;
    private StorageChangeConsumer $consumer;
    private const ID = 'media-123';
    private const ORIGINAL = 'orig/ab/cd/content.jpg';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/storage-tracking-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/files', 0777, true);
        $this->kernel = new TrackingFixtureKernel($this->root);
        $this->kernel->boot();
        $c = $this->kernel->getContainer()->get('test.service_container');
        $this->em = $c->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema(array_map($this->em->getClassMetadata(...), [Storage::class, StorageNode::class, StorageChange::class, StorageJournalHead::class]));
        $this->fs = $c->get(StorageService::class)->getZone('fixture.storage');
        $this->journal = $c->get(StorageJournal::class);
        $this->consumer = $c->get(StorageChangeConsumer::class);
        $this->fs->write(self::ORIGINAL, 'image bytes');
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root);
    }

    private function path(string $task): string
    {
        return MediaKeyService::archivePathFromKey(self::ID, $task.'.json');
    }

    private function original(): StorageNode
    {
        $this->em->clear();

        return $this->em->find(StorageNode::class, StorageNode::calcCode('fixture.storage', self::ORIGINAL));
    }

    private function write(string $task, array $result): void
    {
        $this->fs->write($this->path($task), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function drain(): int
    {
        $c = $this->kernel->getContainer()->get('test.service_container');
        $queue = $c->get('messenger.transport.storage.fetch');
        $count = 0;
        while ($queue->getMessageCount()) {
            foreach ($queue->get() as $envelope) {
                $c->get(MessageBusInterface::class)->dispatch($envelope->with(new ReceivedStamp('storage.fetch')));
                $queue->ack($envelope);
                self::assertLessThan(100, ++$count);
            }
        }

        return $count;
    }

    private function cached(string $task): ?array
    {
        $this->em->clear();
        $node = $this->em->find(StorageNode::class, StorageNode::calcCode('fixture.storage', $this->path($task)));

        return isset($node->meta[StorageNode::CONTENT]) ? json_decode($node->meta[StorageNode::CONTENT], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function testPopulateQueuesDirectoriesAndFilesWithoutLoadingContentsInline(): void
    {
        $this->write('observe', ['caption' => 'first']);
        $this->write('ocr', ['text' => 'hello']);
        $c = $this->kernel->getContainer()->get('test.service_container');
        $c->get(\Survos\StorageBundle\Service\DirectoryScanStarter::class)->start('fixture.storage', '');
        self::assertNull($this->cached('observe'));
        self::assertGreaterThan(3, $this->drain());
        self::assertSame(['caption' => 'first'], $this->cached('observe'));
        self::assertSame(['text' => 'hello'], $this->cached('ocr'));
        self::assertSame(0, $this->em->getRepository(StorageNode::class)->count(['status' => StorageNode::STATUS_NEW]));
    }

    public function testChangelogOnlyQueuesWorkflowAndTasksRemainIndependent(): void
    {
        $this->write('observe', ['caption' => 'first']);
        $this->write('ocr', ['text' => 'hello']);
        self::assertSame(3, $this->consumer->consume());
        self::assertNull($this->cached('observe'));
        $this->drain();
        $this->write('observe', ['caption' => 'updated']);
        $this->consumer->consume();
        self::assertSame(['caption' => 'first'], $this->cached('observe'));
        $this->drain();
        self::assertSame(['caption' => 'updated'], $this->cached('observe'));
        self::assertSame(['text' => 'hello'], $this->cached('ocr'));
        $this->fs->delete($this->path('ocr'));
        $this->consumer->consume();
        $this->drain();
        self::assertNull($this->cached('ocr'));
        self::assertSame(['caption' => 'updated'], $this->cached('observe'));
    }

    public function testExistingUnloadedNodesAreLoadedByPopulate(): void
    {
        $this->write('observe', ['caption' => 'restored']);
        $c = $this->kernel->getContainer()->get('test.service_container');
        $starter = $c->get(\Survos\StorageBundle\Service\DirectoryScanStarter::class);
        $starter->start('fixture.storage', '');
        $this->drain();
        $node = $this->em->find(StorageNode::class, StorageNode::calcCode('fixture.storage', $this->path('observe')));
        unset($node->meta[StorageNode::CONTENT]);
        $this->em->flush();
        $starter->start('fixture.storage', '');
        $this->drain();
        self::assertSame(['caption' => 'restored'], $this->cached('observe'));
    }

    public function testFailedWriteDoesNotAppend(): void
    {
        try {
            $this->fs->write(self::ORIGINAL.'/cannot-write', 'bad');
            self::fail('Expected write failure');
        } catch (\League\Flysystem\UnableToWriteFile|\League\Flysystem\UnableToCreateDirectory) {
        }
        self::assertCount(1, $this->journal->since());
    }

    public function testEndpointDeniesAnonymousAccess(): void
    {
        $controller = new \Survos\StorageBundle\Controller\StorageChangesController($this->journal);
        self::assertSame(403, $controller()->getStatusCode());
    }
}
