<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Seo;

use SilverShop\Seo\StructuredData\SeoContext;
use SilverShop\Seo\StructuredData\StructuredDataProvider;
use SilverShop\StoreLocations\Control\StoreLocatorPageController;
use SilverShop\StoreLocations\Page\StoreLocatorPage;

/**
 * silvershop-suite/seo bridge: on a location page, adds the location as a Store / LocalBusiness node (linked to the
 * site organization); on the locator page, an ItemList of all locations.
 *
 * Declared only when the seo interface exists, and registered via _config/seo.yml only when seo is installed, so
 * the module boots without it.
 */
if (interface_exists(StructuredDataProvider::class)) {

class StoreLocationSeoProvider implements StructuredDataProvider
{
    public function getName(): string
    {
        return 'store-locations';
    }

    public function appliesTo(SeoContext $context): bool
    {
        return $context->getRecord() instanceof StoreLocatorPage;
    }

    public function getNodes(SeoContext $context): array
    {
        $controller = $context->getController();
        $location = $controller instanceof StoreLocatorPageController ? $controller->getCurrentLocation() : null;

        if ($location) {
            $node = StoreLocationStructuredData::singleton()->node($location);
            $node['parentOrganization'] = ['@id' => $context->siteId('organization')];
            return [$node];
        }

        $page = $context->getRecord();
        if (!$page instanceof StoreLocatorPage) {
            return [];
        }
        $items = [];
        $position = 1;
        foreach ($page->Locations() as $loc) {
            $url = $loc->AbsoluteLink();
            if ($url) {
                $items[] = ['@type' => 'ListItem', 'position' => $position++, 'url' => $url, 'name' => (string) $loc->Title];
            }
        }

        return $items === [] ? [] : [[
            '@type' => 'ItemList',
            '@id' => $context->nodeId('locations'),
            'itemListElement' => $items,
        ]];
    }
}

}
