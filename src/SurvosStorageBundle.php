<?php

namespace Survos\StorageBundle;

use League\FlysystemBundle\FlysystemBundle;
use Survos\StorageBundle\Command\IterateCommand;
use Survos\StorageBundle\Command\PopulateCommand;
use Survos\StorageBundle\Command\StorageConfigCommand;
use Survos\StorageBundle\Command\StorageListCommand;
use Survos\StorageBundle\Command\StorageDownloadCommand;
use Survos\StorageBundle\Command\StorageUploadCommand;
use Survos\StorageBundle\Controller\StorageController;
use Survos\StorageBundle\Service\StorageService;
use Survos\StorageBundle\Twig\TwigExtension;
use Survos\SimpleDatatables\SurvosSimpleDatatablesBundle;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosStorageBundle extends AbstractBundle implements CompilerPassInterface
{

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        $storageService = $container->getDefinition('survos_storage.storage_service');

        $taggedServices = $container->findTaggedServiceIds('flysystem.storage');
        $adapters = [];
        // if the order is the same, we can get the adapter by the index.  Ugh.
        $loop = 0;
        foreach ($taggedServices as $id => $attributes) {
            $service = $container->get($id);
//            $adapters[$id] = $container->get($id);

            $storageService->addMethodCall('addAdapter',
                [$id, $loop]
            );
            $loop++;

        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // get all bundles https://symfony.com/doc/current/bundles/prepend_extension.html
        $bundles = $builder->getParameter('kernel.bundles');


        $hasSimpleDatatables = in_array(FlysystemBundle::class, array_values($bundles));

        $serviceId = 'survos_storage.storage_service';
        $container->services()->alias(StorageService::class, $serviceId);
        $builder->autowire($serviceId, StorageService::class)
            ->setArgument('$config', $config)
            ->setAutoconfigured(true)
            ->setAutowired(true)
            ->setPublic(true);

        $builder->autowire(StorageController::class)
            ->setArgument('$simpleDatatablesInstalled', $hasSimpleDatatables)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
        ;

        foreach ([
            PopulateCommand::class,
            StorageListCommand::class,
            IterateCommand::class,
            StorageUploadCommand::class,
            StorageDownloadCommand::class,
        ] as $commandName) {
            $builder->autowire($commandName)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

        // Messenger handlers
        $builder->autowire(\Survos\StorageBundle\MessageHandler\DirectoryListingMessageHandler::class)
            ->setAutoconfigured(true)
            ->addTag('messenger.message_handler');


//        // twig classes, for storage_url ?
//        $builder
//            ->autowire('survos.storage_twig', TwigExtension::class)
//            ->setAutoconfigured(true)
//            ->addTag('twig.extension');
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $rootNode = $definition->rootNode();
        $rootNode
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
            ->end();

    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $bundles = $builder->getParameter('kernel.bundles');
        $bundleClasses = array_values($bundles);
        $hasImgproxy = in_array('Mezcalito\\ImgproxyBundle\\ImgproxyBundle', $bundleClasses, true);

        if ($hasImgproxy) {
            $builder->prependExtensionConfig('imgproxy', [
                'presets' => [
                    'storage_thumb' => [
                        'format' => 'webp',
                        'options' => [
                            'resize' => [
                                'resizing_type' => 'fit',
                                'width' => 220,
                                'height' => 220,
                            ],
                        ],
                    ],
                    'storage_preview' => [
                        'format' => 'webp',
                        'options' => [
                            'resize' => [
                                'resizing_type' => 'fit',
                                'width' => 1280,
                                'height' => 1280,
                            ],
                        ],
                    ],
                ],
            ]);
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'SurvosStorageBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/src/Entity',
                        'prefix' => 'Survos\\StorageBundle\\Entity',
                        'alias' => 'Storage',
                    ],
                ],
            ],
        ]);
    }

}
