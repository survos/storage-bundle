<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Tests;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Survos\StorageBundle\Service\StorageService;
use Survos\StorageBundle\SurvosStorageBundle;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;

final class BrowseStoragesTest extends TestCase
{
    public function testBrowseSelectionIsSeparateFromTrackingAndServiceAccess(): void
    {
        $tree = new TreeBuilder('survos_storage');
        (new SurvosStorageBundle())->configure(new DefinitionConfigurator($tree, $this->createStub(\Symfony\Component\Config\Definition\Loader\DefinitionFileLoader::class), __FILE__, __FILE__));
        $config = (new Processor())->process($tree->buildTree(), [[
            'browse_storages' => ['visible.storage'],
            'storages' => ['hidden.storage' => ['track' => true]],
        ]]);
        self::assertTrue($config['storages']['hidden.storage']['track']);
        $files = new \ArrayObject([
            new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir())),
            new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir())),
        ]);
        $service = new StorageService($files, $config, ['visible.storage', 'hidden.storage']);
        self::assertSame(['visible.storage'], array_keys($service->getBrowseAdapters()));
        self::assertCount(2, $service->getZones());
        self::assertSame($files[1], $service->getZone('hidden.storage'));
        $default = new StorageService($files, [], ['visible.storage', 'hidden.storage']);
        self::assertCount(2, $default->getBrowseAdapters());
    }
}
