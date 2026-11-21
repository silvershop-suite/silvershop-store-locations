<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Page;

use Page;
use SilverShop\StoreLocations\Control\StoreLocatorPageController;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\ORM\DataList;

/**
 * Lists the store locations; each location gets its own detail URL below this page ("/stores/delft").
 */
class StoreLocatorPage extends Page
{
    private static string $table_name = 'SilverShop_StoreLocatorPage';

    private static string $controller_name = StoreLocatorPageController::class;

    private static string $description = 'Lists your store locations with address and opening hours.';

    private static string $icon_class = 'font-icon-p-map';

    private static string $singular_name = 'Store locator page';

    private static string $plural_name = 'Store locator pages';

    /**
     * Locations shown on the page (permanently closed ones are left out).
     */
    public function Locations(): DataList
    {
        return StoreLocation::get()->exclude('Status', StoreLocation::STATUS_PERMANENTLY_CLOSED);
    }
}
