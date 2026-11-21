<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\View;

use SilverShop\StoreLocations\Control\StoreLocatorPageController;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverShop\StoreLocations\Page\StoreLocatorPage;
use SilverStripe\ORM\DataList;
use SilverStripe\View\TemplateGlobalProvider;

/**
 * Site-wide template variables: `$StoreLocations`, `$PrimaryStoreLocation`, `$StoreLocatorPage`.
 */
class StoreLocationsTemplateProvider implements TemplateGlobalProvider
{
    public static function get_template_global_variables()
    {
        return [
            'StoreLocations' => 'storeLocations',
            'PrimaryStoreLocation' => 'primaryStoreLocation',
            'StoreLocatorPage' => 'storeLocatorPage',
        ];
    }

    public static function storeLocations(): DataList
    {
        StoreLocatorPageController::requireAssets();
        return StoreLocation::get()->exclude('Status', StoreLocation::STATUS_PERMANENTLY_CLOSED);
    }

    public static function primaryStoreLocation(): ?StoreLocation
    {
        StoreLocatorPageController::requireAssets();
        $listed = StoreLocation::get()->exclude('Status', StoreLocation::STATUS_PERMANENTLY_CLOSED);
        return $listed->filter('IsPrimary', true)->first() ?? $listed->first();
    }

    public static function storeLocatorPage(): ?StoreLocatorPage
    {
        return StoreLocatorPage::get()->first();
    }
}
