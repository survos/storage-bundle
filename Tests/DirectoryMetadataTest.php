<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Survos\StorageBundle\Entity\Storage;
use Survos\StorageBundle\Entity\StorageNode;
use Survos\StorageBundle\Event\StorageNodeDiscoveredEvent;
use Survos\StorageBundle\Service\DirectoryScanner;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DirectoryMetadataTest extends TestCase
{
    public function testListingCapturesMetadataAndDetectsChangesWithoutPerFileRequests(): void
    {
        $adapter = $this->createMock(FilesystemAdapter::class);
        $adapter->expects(self::exactly(2))->method('listContents')->willReturnOnConsecutiveCalls(
            [new FileAttributes('example.tar', 42, lastModified: 100, extraMetadata: [StorageNode::ETAG => '"first-2"', StorageNode::STORAGE_CLASS => 'STANDARD'])],
            [new FileAttributes('example.tar', 42, lastModified: 100, extraMetadata: [StorageNode::ETAG => '"second-2"', StorageNode::STORAGE_CLASS => 'GLACIER'])],
        );
        foreach (['read', 'readStream', 'fileSize', 'lastModified', 'mimeType'] as $method) {
            $adapter->expects(self::never())->method($method);
        }
        $service = $this->createStub(StorageService::class);
        $service->method('getZone')->willReturn(new Filesystem($adapter));
        $em = $this->createMock(EntityManagerInterface::class);
        $child = null;
        $em->method('find')->willReturnCallback(static function () use (&$child) { return $child; });
        $em->expects(self::once())->method('persist')->willReturnCallback(static function ($node) use (&$child): void { $child = $node; });
        $events = new EventDispatcher();
        $changes = [];
        $events->addListener(StorageNodeDiscoveredEvent::class, static function ($event) use (&$changes): void { $changes[] = $event->changed; });
        $scanner = new DirectoryScanner($em, $service, $events);
        $root = new StorageNode(new Storage('test.storage'), '', isDir: true);
        $scanner->scan($root);
        self::assertSame('"first-2"', $child->etag);
        self::assertSame('STANDARD', $child->storageClass);
        $scanner->scan($root);
        self::assertSame('"second-2"', $child->etag);
        self::assertSame('GLACIER', $child->storageClass);
        self::assertSame([true, true], $changes);
    }
}
