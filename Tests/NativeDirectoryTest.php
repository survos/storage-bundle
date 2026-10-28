<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tests;

use Doctrine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use League;
use PHPUnit\Framework\TestCase;
use Psr;
use Survos;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Event\StorageNodeDiscoveredEvent;
use Survos\StorageBundle\Service\DirectoryScanStarter;
use Survos\StorageBundle\Workflow\DirectoryWorkflow;
use Symfony;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class StorageFixtureKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(public readonly string $fixture)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new Symfony\Bundle\TwigBundle\TwigBundle();
        yield new Doctrine\Bundle\DoctrineBundle\DoctrineBundle();
        yield new League\FlysystemBundle\FlysystemBundle();
        yield new Survos\StorageBundle\SurvosStorageBundle();
    }

    public function getCacheDir(): string
    {
        return $this->fixture.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->fixture.'/log';
    }

    public function getProjectDir(): string
    {
        return $this->fixture;
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'storage-fixture', 'test' => true,
            'router' => ['utf8' => true],
            'lock' => 'flock',
            'messenger' => ['transports' => ['storage.fetch' => 'in-memory://', 'sync' => 'sync://']],
        ]);
        $container->services()->set('logger', Psr\Log\NullLogger::class);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'path' => $this->fixture.'/db.sqlite'],
            'orm' => ['enable_native_lazy_objects' => true],
        ]);
        $container->extension('flysystem', ['storages' => [
            'fixture.storage' => ['local' => ['directory' => $this->fixture.'/files']],
        ]]);
        $container->extension('survos_storage', ['routes_enabled' => false]);
        $container->extension('survos_workflow_extras', ['routes_enabled' => false]);
    }
}

#[\PHPUnit\Framework\Attributes\CoversNothing]
final class NativeDirectoryTest extends TestCase
{
    private StorageFixtureKernel $kernel;
    private string $root;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/storage-native-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/files/alpha/nested', 0777, true);
        mkdir($this->root.'/files/beta', 0777, true);
        file_put_contents($this->root.'/files/root.txt', 'root');
        file_put_contents($this->root.'/files/alpha/a.txt', 'alpha');
        file_put_contents($this->root.'/files/alpha/nested/b.txt', 'nested');
        file_put_contents($this->root.'/files/beta/c.txt', 'beta');
        $this->kernel = new StorageFixtureKernel($this->root);
        $this->kernel->boot();
        $this->em = $this->container()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(Storage::class), $this->em->getClassMetadata(StorageNode::class),
        ]);
    }

    private function container(): Psr\Container\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        (new Symfony\Component\Filesystem\Filesystem())->remove($this->root);
    }

    private function queue(): Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport
    {
        return $this->container()->get('messenger.transport.storage.fetch');
    }

    private function drain(): int
    {
        $count = 0;
        while ($this->queue()->getMessageCount()) {
            foreach ($this->queue()->get() as $envelope) {
                $this->container()->get(MessageBusInterface::class)->dispatch($envelope->with(new ReceivedStamp('storage.fetch')));
                $this->queue()->ack($envelope);
                self::assertLessThan(12, ++$count);
            }
        }

        return $count;
    }

    private function assertTree(): void
    {
        $this->em->clear();
        $nodes = $this->em->getRepository(StorageNode::class);
        self::assertSame(8, $nodes->count([]));
        self::assertSame(4, $nodes->count(['status' => StorageNode::STATUS_DIR_LOADED]));
        self::assertSame(4, $nodes->count(['status' => StorageNode::STATUS_FILE_LOADED]));
        $leaf = $nodes->findOneBy(['path' => 'alpha/nested/b.txt']);
        self::assertSame('alpha/nested', $leaf->parent->path);
        self::assertSame(6, $leaf->fileSize);
    }

    public function testPersistAloneKicksOffAndFansOut(): void
    {
        $storage = new Storage('fixture.storage');
        $node = new StorageNode($storage, '', isDir: true);
        $node->name = 'Root';
        $this->em->persist($storage);
        $this->em->persist($node);
        self::assertSame(0, $this->queue()->getMessageCount());
        $this->em->flush();
        self::assertSame(1, $this->queue()->getMessageCount());
        self::assertSame(DirectoryWorkflow::NAME, $this->queue()->get()[0]->getMessage()->workflow);
        self::assertSame(8, $this->drain());
        $this->assertTree();
    }

    public function testSyncScopeIncludesAllDescendantsAndMetadata(): void
    {
        $this->container()->get('event_dispatcher')->addListener(StorageNodeDiscoveredEvent::class, static function ($event): void {
            $event->node->meta['application'] = 'fixture';
        });
        $this->container()->get(DirectoryScanStarter::class)->start('fixture.storage', '', true);
        self::assertSame(0, $this->queue()->getMessageCount());
        $this->assertTree();
        self::assertSame('fixture', $this->em->getRepository(StorageNode::class)->findOneBy(['path' => 'beta/c.txt'])->meta['application']);
    }

    public function testDuplicateDeliveryIsBlockedWithoutNewFanOut(): void
    {
        $this->container()->get(DirectoryScanStarter::class)->start('fixture.storage', '');
        $duplicate = $this->queue()->get()[0];
        $this->drain();
        $this->container()->get(MessageBusInterface::class)->dispatch($duplicate->with(new ReceivedStamp('storage.fetch')));
        $this->drain();
        self::assertSame(0, $this->queue()->getMessageCount());
        $this->assertTree();
    }
}
