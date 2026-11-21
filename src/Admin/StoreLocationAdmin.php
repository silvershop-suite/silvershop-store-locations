<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Admin;

use SilverShop\StoreLocations\Model\LocationFeature;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldComponent;
use SilverStripe\ORM\DataList;

/**
 * CMS section for locations, closing days that apply to all locations, and location features.
 */
class StoreLocationAdmin extends ModelAdmin
{
    private static string $url_segment = 'locations';

    private static string $menu_title = 'Locations';

    private static string $menu_icon_class = 'font-icon-p-map';

    private static array $managed_models = [
        'locations' => [
            'dataClass' => StoreLocation::class,
        ],
        'closing-days' => [
            'dataClass' => OpeningException::class,
        ],
        'features' => [
            'dataClass' => LocationFeature::class,
        ],
    ];

    public function getManagedModels()
    {
        $models = parent::getManagedModels();
        // A keyed entry with a dataClass gets no default title from ModelAdmin, so give every tab one.
        foreach ($models as $tab => $options) {
            if (!isset($options['title'])) {
                $models[$tab]['title'] = singleton($options['dataClass'])->i18n_plural_name();
            }
        }
        if (isset($models['closing-days'])) {
            $models['closing-days']['title'] = _t(self::class . '.ClosingDays', 'Closing days (all locations)');
        }

        return $models;
    }

    /**
     * The closing-days tab only shows exceptions that apply to all locations.
     */
    public function getList(): DataList
    {
        $list = parent::getList();
        if ($this->modelClass === OpeningException::class) {
            $list = $list->filter('StoreLocationID', 0);
        }

        return $list;
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        $orderableRows = 'Symbiote\\GridFieldExtensions\\GridFieldOrderableRows';
        $orderable = in_array($this->modelClass, [StoreLocation::class, LocationFeature::class], true)
            && class_exists($orderableRows);
        if ($orderable) {
            $grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
            if ($grid instanceof GridField) {
                /** @var GridFieldComponent $component */
                $component = Injector::inst()->create($orderableRows, 'Sort');
                $grid->getConfig()->addComponent($component);
            }
        }

        return $form;
    }
}
