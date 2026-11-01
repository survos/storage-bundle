<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Menu;

use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\StorageBundle\Service\StorageService;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StorageMenuSubscriber extends AbstractAdminMenuSubscriber
{
    public function __construct(
        private readonly StorageService $storageService,
        ?RouterInterface $router = null,
        ?RouteAliasService $routeAliasService = null,
        ?IconService $iconService = null,
        ?ManagerRegistry $managerRegistry = null,
        ?EntityMetaRegistry $entityMetaRegistry = null,
        private readonly ?TranslatorInterface $translator = null,
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

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $submenu = $this->addSubmenu($event->getMenu(), $this->getLabel(), $this->getGroupIcon());
        $this->add($submenu, 'survos_storage_zones', label: 'All zones', icon: 'server');

        foreach ($this->storageService->getBrowseAdapters() as $zoneId => $adapter) {
            $key = 'label.'.$zoneId;
            $label = $this->translator?->trans($key, [], 'storage') ?? $key;
            $label = $label === $key ? $zoneId : $label;
            if ('' !== $adapter->destination) {
                $label .= ' — '.$adapter->destination;
            }
            $this->add($submenu, 'survos_storage_zone', ['zoneId' => $zoneId], label: $label, icon: 'folder');
        }
    }
}
