<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;

/**
 * A facility a location offers (parking, wheelchair access, pickup point, ...), shown on the location and usable
 * as a filter in the store locator.
 *
 * @property string $Title
 * @property string $IconClass
 * @property int $Sort
 * @method ManyManyList<StoreLocation> Locations()
 */
class LocationFeature extends DataObject
{
    private static string $table_name = 'SilverShop_StoreLocationFeature';

    private static array $db = [
        'Title' => 'Varchar',
        'IconClass' => 'Varchar(64)',
        'Sort' => 'Int',
    ];

    private static array $belongs_many_many = [
        'Locations' => StoreLocation::class,
    ];

    private static string $default_sort = '"Sort" ASC, "Title" ASC';

    private static array $summary_fields = [
        'Title' => 'Title',
    ];

    private static string $singular_name = 'Feature';

    private static string $plural_name = 'Features';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Sort', 'Locations']);
        $fields->dataFieldByName('IconClass')?->setDescription(_t(
            self::class . '.IconClassDescription',
            'Optional icon CSS class, e.g. "ss-icon-car" (Lucide via silvershop-suite/cms-ui).'
        ));

        return $fields;
    }

    public function canView($member = null)
    {
        return true;
    }
}
