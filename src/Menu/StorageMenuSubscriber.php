<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Menu;

use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\StorageBundle\Service\StorageService;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

final class StorageMenuSubscriber extends AbstractAdminMenuSubscriber
{
    public function __construct(
        private readonly StorageService $storageService,
        ?RouterInterface $router = null,
        ?RouteAliasService $routeAliasService = null,
        ?IconService $iconService = null,
        ?ManagerRegistry $managerRegistry = null,
        ?EntityMetaRegistry $entityMetaRegistry = null,
    ) {
        parent::__construct($router, $routeAliasService, $iconService, $managerRegistry, $entityMetaRegistry);
    }

    protected function getLabel(): string
    {
        return 'Storage';
    }

    protected function getResourceClasses(): array
    {
        return [];
    }

    protected function getGroupIcon(): ?string
    {
        return 'database';
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $submenu = $this->addSubmenu($event->getMenu(), $this->getLabel(), $this->getGroupIcon());
        $this->add($submenu, 'survos_storage_zones', label: 'All zones', icon: 'server');

        foreach (array_keys($this->storageService->getZones()) as $zoneId) {
            $this->add($submenu, 'survos_storage_zone', ['zoneId' => $zoneId], label: $zoneId, icon: 'folder');
        }
    }
}
