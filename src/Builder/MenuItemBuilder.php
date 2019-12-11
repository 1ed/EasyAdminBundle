<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Builder;

use EasyCorp\Bundle\EasyAdminBundle\Configuration\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Context\ApplicationContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\ItemCollectionBuilderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\MenuItemDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\CrudUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Security\Permission;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MenuItemBuilder implements ItemCollectionBuilderInterface
{
    public const TYPE_CRUD = 'crud';
    public const TYPE_DASHBOARD = 'dashboard';
    public const TYPE_EXIT_IMPERSONATION = 'exit_impersonation';
    public const TYPE_LOGOUT = 'logout';
    public const TYPE_ROUTE = 'route';
    public const TYPE_SECTION = 'section';
    public const TYPE_SUBMENU = 'submenu';
    public const TYPE_URL = 'url';

    private $isBuilt;
    /** @var MenuItemDto[] */
    private $builtMenuItems;
    /** @var MenuItem[] */
    private $menuItems;
    private $applicationContextProvider;
    private $authChecker;
    private $translator;
    private $urlGenerator;
    private $logoutUrlGenerator;
    private $crudRouter;

    public function __construct(ApplicationContextProvider $applicationContextProvider, AuthorizationCheckerInterface $authChecker, TranslatorInterface $translator, UrlGeneratorInterface $urlGenerator, LogoutUrlGenerator $logoutUrlGenerator, CrudUrlGenerator $crudRouter)
    {
        $this->applicationContextProvider = $applicationContextProvider;
        $this->authChecker = $authChecker;
        $this->translator = $translator;
        $this->urlGenerator = $urlGenerator;
        $this->logoutUrlGenerator = $logoutUrlGenerator;
        $this->crudRouter = $crudRouter;
    }

    /**
     * @param MenuItem $menuItem
     */
    public function addItem($menuItem): ItemCollectionBuilderInterface
    {
        $this->menuItems[] = $menuItem;
        $this->resetBuiltMenuItems();

        return $this;
    }

    /**
     * @param MenuItem[] $menuItems
     * @return ItemCollectionBuilderInterface
     */
    public function setItems(array $menuItems): ItemCollectionBuilderInterface
    {
        $this->menuItems = $menuItems;
        $this->resetBuiltMenuItems();

        return $this;
    }

    /**
     * @return MenuItemDto[]
     */
    public function build(): array
    {
        if (!$this->isBuilt) {
            $this->buildMenuItems();
            $this->isBuilt = true;
        }

        return $this->builtMenuItems;
    }

    private function resetBuiltMenuItems(): void
    {
        $this->builtMenuItems = [];
        $this->isBuilt = false;
    }

    private function buildMenuItems(): void
    {
        $this->resetBuiltMenuItems();

        $applicationContext = $this->applicationContextProvider->getContext();
        $defaultTranslationDomain = $applicationContext->getDashboard()->getTranslationDomain();
        $dashboardRouteName = $applicationContext->getDashboard()->getRouteName();

        foreach ($this->menuItems as $i => $menuItem) {
            $menuItemDto = $menuItem->getAsDto();
            if (false === $this->authChecker->isGranted(Permission::EA_VIEW_MENU_ITEM, $menuItemDto)) {
                continue;
            }

            $subItems = [];
            /** @var MenuItem $menuSubItemConfig */
            foreach ($menuItemDto->getSubItems() as $j => $menuSubItemConfig) {
                $menuSubItemContext = $menuSubItemConfig->getAsDto();
                if (false === $this->authChecker->isGranted($menuSubItemContext->getPermission())) {
                    continue;
                }

                $subItems[] = $this->buildMenuItem($menuSubItemContext, [], $i, $j, $defaultTranslationDomain, $dashboardRouteName);
            }

            $builtItem = $this->buildMenuItem($menuItemDto, $subItems, $i, -1, $defaultTranslationDomain, $dashboardRouteName);

            $this->builtMenuItems[] = $builtItem;
        }

        $this->isBuilt = true;
    }

    private function buildMenuItem(MenuItemDto $menuItemDto, array $subItemsContext, int $index, int $subIndex, string $defaultTranslationDomain, string $dashboardRouteName): MenuItemDto
    {
        $label = $this->translator->trans($menuItemDto->getLabel(), [], $menuItemDto->getTranslationDomain() ?? $defaultTranslationDomain);
        $url = $this->generateMenuItemUrl($menuItemDto, $dashboardRouteName, $index, $subIndex);

        return $menuItemDto->with([
            'index' => $index,
            'subIndex' => $subIndex,
            'label' => $label,
            'linkUrl' => $url,
            'subItems' => $subItemsContext,
        ]);
    }

    private function generateMenuItemUrl(MenuItemDto $menuItemContext, string $dashboardRouteName, int $index, int $subIndex): string
    {
        switch ($menuItemContext->getType()) {
            case self::TYPE_URL:
                return $menuItemContext->getLinkUrl();

            case self::TYPE_DASHBOARD:
                return $this->urlGenerator->generate($dashboardRouteName);

            case self::TYPE_ROUTE:
                // add the index and subIndex query parameters to display the selected menu item
                // remove the 'query' parameter to not perform a search query when clicking on menu items
                $defaultRouteParameters = ['menuIndex' => $index, 'submenuIndex' => $subIndex, 'query' => null];
                $routeParameters = array_merge($defaultRouteParameters, $menuItemContext->getRouteParameters());

                return $this->urlGenerator->generate($menuItemContext->getRouteName(), $routeParameters);

            case self::TYPE_CRUD:
                // add the index and subIndex query parameters to display the selected menu item
                // remove the 'query' parameter to not perform a search query when clicking on menu items
                $defaultRouteParameters = ['menuIndex' => $index, 'submenuIndex' => $subIndex, 'query' => null];
                $routeParameters = array_merge($defaultRouteParameters, $menuItemContext->getRouteParameters());

                return $this->crudRouter->generate($routeParameters);

            case self::TYPE_LOGOUT:
                return $this->logoutUrlGenerator->getLogoutPath();

            case self::TYPE_EXIT_IMPERSONATION:
                // the switch parameter name can be changed, but this code assumes it's always
                // the default one because Symfony doesn't provide a generic exitImpersonationUrlGenerator
                return '?_switch_user=_exit';

            case self::TYPE_SECTION:
                return '#';

            default:
                return '';
        }
    }
}