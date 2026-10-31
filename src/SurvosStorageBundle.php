<?php

declare(strict_types=1);

namespace Survos\StorageBundle;

use League\FlysystemBundle\FlysystemBundle;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\StorageBundle\Command\IterateCommand;
use Survos\StorageBundle\Command\PopulateCommand;
use Survos\StorageBundle\Command\StorageConfigCommand;
use Survos\StorageBundle\Command\StorageDownloadCommand;
use Survos\StorageBundle\Command\StorageListCommand;
use Survos\StorageBundle\Command\StorageUploadCommand;
use Survos\StorageBundle\Controller\StorageController;
use Survos\StorageBundle\Service\StorageService;
use Survos\StorageBundle\Twig\TwigExtension;
use Survos\WorkflowMessengerBundle\SurvosWorkflowMessengerBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

#[RequiredBundle(SurvosWorkflowMessengerBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
class SurvosStorageBundle extends AbstractSurvosBundle implements CompilerPassInterface
{
    use HasConfigurableRoutes;
    /** Keys under a storage that are Filesystem options rather than an adapter block. */
    private const NON_ADAPTER_KEYS = ['public_url', 'temporary_url', 'options'];

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $this->addRouteLoaderCompilerPass($container);
    }

    public function process(ContainerBuilder $container): void
    {
        $storageService = $container->getDefinition('survos_storage.storage_service');
        $tracking = $container->getParameter('survos_storage.tracking');

        // The DECLARED config, straight from the app's flysystem.yaml:
        //
        //   storages:
        //     platform_vault.storage:
        //       aws: { bucket: survos-platform, prefix: vault }
        //
        // This is the honest source for "what is this zone". The alternative — reflecting into
        // the built adapter for private $bucket/$prefixer/$rootLocation — reads differently named
        // internals per adapter class, fatals on adapters that connect lazily and leave typed
        // properties uninitialized, and tells you nothing for an adapter it has no case for.
        // Reading the adapter Definition's constructor arguments instead would be no better: they
        // are positional, so it breaks silently whenever an adapter reorders its constructor.
        //
        // Config keys are named, stable, and are literally what the user wrote.
        $storageConfig = $this->flysystemStorageConfig($container);

        // The tag carries the storage name (FlysystemExtension: addTag('flysystem.storage',
        // ['storage' => $storageName])), so the index has to line up with the iteration order of
        // the tagged services only, not with anything else. Previously the name was inferred from
        // position alone, with the comment "if the order is the same, we can get the adapter by
        // the index. Ugh."
        $loop = 0;
        foreach ($container->findTaggedServiceIds('flysystem.storage') as $id => $attributes) {
            // Note: do NOT call $container->get($id) here. Instantiating a flysystem storage at
            // compile time forces eager construction of its adapter (e.g. the AWS S3 client)
            // while %env()% placeholders are still unresolved, which throws "Region must be a
            // valid RFC host label". The definition and the tag are enough.
            $name = $attributes[0]['storage'] ?? $id;
            if (($tracking[$name]['track'] ?? false) && !$container->hasDefinition($id.'.survos_tracked_adapter')) {
                $filesystem = $container->getDefinition($id);
                $wrapper = $id.'.survos_tracked_adapter';
                $container->register($wrapper, Tracking\TrackedAdapter::class)->setArguments([
                    $filesystem->getArgument(0), new Reference(Tracking\StorageJournal::class), $name,
                ]);
                $filesystem->setArgument(0, new Reference($wrapper));
            }

            $storageService->addMethodCall('addAdapter', [$id, $loop, $storageConfig[$name] ?? []]);
            ++$loop;
        }
        foreach ($tracking as $zone => $options) {
            if ($options['track'] && !$container->hasDefinition($zone)) {
                throw new \LogicException('Unknown tracked Flysystem zone: '.$zone);
            }
        }
    }

    /**
     * Storage name => ['adapter' => 'aws', 'bucket' => ..., 'prefix' => ..., 'directory' => ...].
     *
     * getExtensionConfig() returns each contributing config file separately and unmerged, so
     * later sources (when@dev overrides, for instance) have to be folded over earlier ones by
     * hand. %env()% placeholders are left intact on purpose: passing them through as arguments
     * lets the container resolve them at runtime, so a zone shows its real bucket rather than the
     * literal "%env(PLATFORM_S3_BUCKET)%".
     *
     * @return array<string, array<string, mixed>>
     */
    private function flysystemStorageConfig(ContainerBuilder $container): array
    {
        $storages = [];
        foreach ($container->getExtensionConfig('flysystem') as $config) {
            foreach ($config['storages'] ?? [] as $name => $definition) {
                if (!\is_array($definition)) {
                    continue;
                }
                $storages[$name] = array_merge($storages[$name] ?? [], $definition);
            }
        }

        $out = [];
        foreach ($storages as $name => $definition) {
            // The adapter type is whichever key holds an options array — 'aws', 'local', 'ftp'...
            // Everything else at this level is a Filesystem option (visibility, read_only, ...).
            foreach ($definition as $key => $options) {
                if (!\is_array($options) || \in_array($key, self::NON_ADAPTER_KEYS, true)) {
                    continue;
                }

                $out[$name] = [
                    'adapter' => $key,
                    'bucket' => $options['bucket'] ?? null,
                    'prefix' => $options['prefix'] ?? null,
                    'directory' => $options['directory'] ?? $options['root'] ?? null,
                ];
                break;
            }
        }

        return $out;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);
        $builder->setParameter('survos_storage.tracking', $config['storages']);
        $builder->autowire(Tracking\TrackingConfig::class)->setArgument('$storages', $config['storages']);
        $builder->autowire(Controller\StorageChangesController::class)->addTag('controller.service_arguments');

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

        if ($config['routes_enabled'] && in_array(\Survos\TablerBundle\SurvosTablerBundle::class, array_values($bundles), true)) {
            $builder->autowire(Menu\StorageMenuSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        foreach ([
            PopulateCommand::class,
            StorageListCommand::class,
            IterateCommand::class,
            StorageUploadCommand::class,
            StorageDownloadCommand::class,
            StorageConfigCommand::class,
        ] as $commandName) {
            $builder->autowire($commandName)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

        foreach ([
            Tracking\StorageJournal::class,
            Tracking\CachedSidecarReader::class,
            Tracking\ExternalStorageWrite::class,
            Tracking\StorageFileLoader::class,
            Tracking\StorageChangeConsumer::class,
            EventListener\NodeRefreshListener::class,
            Service\DirectoryScanner::class,
            Service\DirectoryScanStarter::class,
            Workflow\DirectoryWorkflow::class,
            EventListener\DirectoryScanEvents::class,
            MessageHandler\DirectoryListingMessageHandler::class,
        ] as $class) {
            $builder->autowire($class)->setAutoconfigured(true);
        }

        $builder->getDefinition(Tracking\StorageChangeConsumer::class)->setArgument('$locks', new Reference($config['tracking_lock_factory']));

        //        // twig classes, for storage_url ?
        //        $builder
        //            ->autowire('survos.storage_twig', TwigExtension::class)
        //            ->setAutoconfigured(true)
        //            ->addTag('twig.extension');
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '/storage');
        $children
            ->booleanNode('enabled')->defaultTrue()->end()
            ->booleanNode('debug')->defaultFalse()->end()
            ->arrayNode('browse_storages')->scalarPrototype()->end()->defaultValue([])->end()
            ->scalarNode('tracking_lock_factory')->defaultValue('lock.factory')->cannotBeEmpty()->end()
            ->arrayNode('storages')->useAttributeAsKey('name')->arrayPrototype()->children()
                ->booleanNode('track')->defaultFalse()->end()
                ->scalarNode('sidecar_prefix')->defaultValue('o')->cannotBeEmpty()->end()
                ->arrayNode('content_patterns')->scalarPrototype()->end()->defaultValue([])->end()
            ->end()->end()->end()
        ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);
        $bundles = $builder->getParameter('kernel.bundles');
        $bundleClasses = array_values($bundles);
        // imgproxy is optional, and two unrelated bundles provide it. mezcalito/imgproxy-bundle
        // is NOT legacy or a stale reference: it's a separate live package whose config root is
        // `imgproxy` (a plain Bundle, so the alias comes from the class name), while
        // survos/imgproxy-bundle's root is `survos_imgproxy`. They don't collide, which is why
        // nothing has broken -- but ssai registers BOTH (config/bundles.php), and the
        // prependExtensionConfig('imgproxy', ...) below targets the Mezcalito one only.
        //
        // Possible point of confusion, deliberately left alone for now: the Mezcalito bundle
        // was adopted in ssai so imgproxy could point at another server, which now matters less
        // since edge appliances run their own imgproxy and serve scan thumbnails directly
        // without a round trip through S3. Consolidating on survos/imgproxy-bundle means porting
        // ssai's config/packages/imgproxy.yaml presets to survos_imgproxy and repointing the
        // prepend below -- an ssai decision, not a search/storage one.
        $hasImgproxy = in_array('Survos\\ImgproxyBundle\\SurvosImgproxyBundle', $bundleClasses, true)
            || in_array('Mezcalito\\ImgproxyBundle\\ImgproxyBundle', $bundleClasses, true);

        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                'aliases' => [
                    'database' => 'tabler:database',
                    'server' => 'tabler:server',
                    'folder' => 'tabler:folder',
                ],
            ]);
        }

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
