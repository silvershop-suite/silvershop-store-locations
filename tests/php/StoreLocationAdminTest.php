<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Tests;

use SilverShop\StoreLocations\Admin\StoreLocationAdmin;
use SilverShop\StoreLocations\Model\LocationFeature;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;

class StoreLocationAdminTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testEveryTabHasATitleAndDataClass(): void
    {
        i18n::set_locale('en_GB');
        $models = StoreLocationAdmin::create()->getManagedModels();

        $this->assertSame(['locations', 'closing-days', 'features'], array_keys($models));
        $this->assertSame(StoreLocation::class, $models['locations']['dataClass']);
        $this->assertSame(OpeningException::class, $models['closing-days']['dataClass']);
        $this->assertSame(LocationFeature::class, $models['features']['dataClass']);
        foreach ($models as $tab => $options) {
            $this->assertNotEmpty($options['title'] ?? '', $tab . ' has a title');
        }
        $this->assertSame('Closing days (all locations)', $models['closing-days']['title']);
    }

    public function testClosingDaysTabOnlyListsExceptionsForAllLocations(): void
    {
        $location = StoreLocation::create(['Title' => 'Delft']);
        $location->write();
        OpeningException::create(['StoreLocationID' => 0, 'DateFrom' => '2026-12-25', 'Reason' => 'Christmas'])->write();
        OpeningException::create(['StoreLocationID' => $location->ID, 'DateFrom' => '2026-12-24', 'Reason' => 'Local'])->write();

        $admin = StoreLocationAdmin::create();
        $reflection = new \ReflectionProperty(StoreLocationAdmin::class, 'modelClass');
        $reflection->setValue($admin, OpeningException::class);

        $this->assertSame(['Christmas'], $admin->getList()->column('Reason'));
    }
}
