<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Tests;

use SilverShop\StoreLocations\Control\StoreLocatorPageController;
use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverShop\StoreLocations\Page\StoreLocatorPage;
use SilverShop\StoreLocations\View\StoreLocationsTemplateProvider;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Page, controller action and templates render under the CMS 6 template engine.
 */
class StoreLocatorPageTest extends SapphireTest
{
    protected $usesDatabase = true;

    private string $previousTimezone = '';

    private StoreLocatorPage $page;

    private StoreLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        i18n::set_locale('en_GB');
        DBDatetime::set_mock_now('2026-11-23 09:00:00');
        OpeningCalendar::singleton()->reset();

        $this->page = StoreLocatorPage::create(['Title' => 'Stores', 'URLSegment' => 'stores']);
        $this->page->write();
        $this->page->publishSingle();

        $this->location = StoreLocation::create([
            'Title' => 'High Street',
            'Street' => 'High Street',
            'HouseNumber' => '1',
            'PostalCode' => '2611 JA',
            'City' => 'Delft',
            'Phone' => '015 212 34 56',
            'Timezone' => 'Europe/Amsterdam',
            'IsPrimary' => true,
            'RegularHours' => WeeklyHoursField::encode([1 => '10:00-18:00', 2 => '10:00-18:00', 3 => '10:00-18:00']),
        ]);
        $this->location->write();
        OpeningException::create([
            'StoreLocationID' => $this->location->ID,
            'DateFrom' => '2026-11-25',
            'Type' => 'Closed',
            'Reason' => 'Staff training',
        ])->write();

        StoreLocation::create(['Title' => 'Old shop', 'Status' => StoreLocation::STATUS_PERMANENTLY_CLOSED])->write();
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    private function controller(): StoreLocatorPageController
    {
        $controller = StoreLocatorPageController::create($this->page);
        $request = new HTTPRequest('GET', 'stores/high-street');
        $request->setSession(new Session([]));
        $controller->setRequest($request);

        return $controller;
    }

    public function testLinksAndListing(): void
    {
        $this->assertStringEndsWith('/stores/high-street', (string) $this->location->Link());
        $this->assertSame(['High Street'], $this->page->Locations()->column('Title'), 'permanently closed is left out');
        $this->assertSame($this->location->ID, StoreLocationsTemplateProvider::primaryStoreLocation()?->ID);
    }

    public function testLocationActionAndTemplate(): void
    {
        $controller = $this->controller();
        $request = new HTTPRequest('GET', 'high-street');
        $request->setRouteParams(['URLSegment' => 'high-street']);

        $data = $controller->location($request);
        $this->assertSame($this->location->ID, $data['Location']->ID);
        $this->assertSame($this->location->ID, $controller->getCurrentLocation()?->ID);

        $html = (string) $controller->customise($data)
            ->renderWith('SilverShop\\StoreLocations\\Page\\Layout\\StoreLocatorPage_location');
        $this->assertStringContainsString('High Street', $html);
        $this->assertStringContainsString('2611 JA Delft', $html);
        $this->assertStringContainsString('Staff training', $html, 'exception shows in the week table and notices');
        $this->assertStringContainsString('Open · closes at 18:00', $html);
        $this->assertStringContainsString('data-hours=', $html);
        $this->assertStringContainsString('application/ld+json', $html, 'standalone JSON-LD without the seo module');
    }

    public function testUnknownLocationIs404(): void
    {
        $request = new HTTPRequest('GET', 'nowhere');
        $request->setRouteParams(['URLSegment' => 'nowhere']);

        $this->expectException(HTTPResponse_Exception::class);
        $this->controller()->location($request);
    }

    public function testListAndFooterTemplatesRender(): void
    {
        $list = (string) $this->controller()->renderWith('SilverShop\\StoreLocations\\Page\\Layout\\StoreLocatorPage');
        $this->assertStringContainsString('High Street', $list);
        $this->assertStringNotContainsString('Old shop', $list);
        $this->assertStringContainsString('tel:0152123456', $list);

        $footer = (string) ModelData::create()->renderWith('SilverShop\\StoreLocations\\Includes\\LocationsFooter');
        $this->assertStringContainsString('High Street', $footer);
        $this->assertStringContainsString('10:00 – 18:00', $footer);
    }
}
