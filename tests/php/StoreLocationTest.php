<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Tests;

use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\OpeningSchedule;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\FieldType\DBDatetime;

class StoreLocationTest extends SapphireTest
{
    protected $usesDatabase = true;

    private string $previousTimezone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        i18n::set_locale('en_GB');
        DBDatetime::set_mock_now('2026-11-23 09:00:00');
        OpeningCalendar::singleton()->reset();
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function make(array $data = []): StoreLocation
    {
        $location = StoreLocation::create(array_merge([
            'Title' => 'Voorstraat',
            'Street' => 'Voorstraat',
            'HouseNumber' => '12a',
            'PostalCode' => '2611 JA',
            'City' => 'Delft',
            'CountryCode' => 'NL',
            'Phone' => '015 - 212 34 56',
            'Timezone' => 'Europe/Amsterdam',
            'RegularHours' => WeeklyHoursField::encode([1 => '10:00-18:00', 2 => '10:00-18:00', 6 => '10:00-17:00']),
        ], $data));
        $location->write();

        return $location;
    }

    public function testURLSegmentIsGeneratedAndUnique(): void
    {
        $first = $this->make(['Title' => 'Markt Nootdorp']);
        $second = $this->make(['Title' => 'Markt Nootdorp']);

        $this->assertSame('markt-nootdorp', $first->URLSegment);
        $this->assertSame('markt-nootdorp-2', $second->URLSegment);
    }

    public function testDefaultsAndCountryOptions(): void
    {
        $location = StoreLocation::create();
        $this->assertSame('NL', $location->CountryCode);
        $this->assertSame('Open', $location->Status);
        $this->assertArrayHasKey('NL', StoreLocation::countryOptions());
    }

    public function testInvalidOpeningHoursAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->make(['RegularHours' => (string) json_encode(['1' => '25:00-26:00'])]);
    }

    public function testInvalidEmailTimezoneAndCoordinatesAreRejected(): void
    {
        foreach ([['Email' => 'not-an-email'], ['Timezone' => 'Mars/Olympus'], ['Latitude' => 95]] as $data) {
            try {
                $this->make($data);
                $this->fail('Expected a validation error for ' . json_encode($data));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlyOnePrimaryLocation(): void
    {
        $a = $this->make(['Title' => 'A', 'IsPrimary' => true]);
        $b = $this->make(['Title' => 'B', 'IsPrimary' => true]);

        $this->assertFalse((bool) StoreLocation::get()->byID($a->ID)?->IsPrimary);
        $this->assertTrue((bool) StoreLocation::get()->byID($b->ID)?->IsPrimary);
    }

    public function testAddressLinesAndLinks(): void
    {
        $location = $this->make(['Latitude' => 52.0116, 'Longitude' => 4.3571]);

        $lines = $location->addressLines();
        $this->assertContains('2611 JA Delft', $lines);
        $this->assertSame('Voorstraat 12a', $location->StreetLine());
        $this->assertSame('tel:0152123456', $location->PhoneLink());
        $this->assertStringContainsString('destination=52.011600%2C4.357100', $location->DirectionsLink());
        $this->assertSame('Manual', $location->GeoSource);

        $noGeo = $this->make(['Title' => 'No geo']);
        $this->assertStringContainsString(rawurlencode('Voorstraat 12a'), $noGeo->DirectionsLink());
    }

    public function testExceptionValidationAndNormalisation(): void
    {
        $location = $this->make();

        $special = OpeningException::create([
            'StoreLocationID' => $location->ID,
            'DateFrom' => '2026-12-24',
            'DateTo' => '2026-12-24',
            'Type' => 'SpecialHours',
            'Hours' => '13-17, 9-12',
        ]);
        $special->write();
        $this->assertSame('09:00-12:00,13:00-17:00', $special->Hours);
        $this->assertNull($special->DateTo, 'a one-day range is stored as a single day');

        $this->expectException(ValidationException::class);
        OpeningException::create(['StoreLocationID' => $location->ID, 'DateFrom' => '2026-12-24', 'Type' => 'SpecialHours'])->write();
    }

    public function testSeasonNeedsDates(): void
    {
        $this->expectException(ValidationException::class);
        OpeningSchedule::create(['StoreLocationID' => $this->make()->ID, 'Title' => 'Summer'])->write();
    }

    public function testStructuredData(): void
    {
        $location = $this->make(['Latitude' => 52.0116, 'Longitude' => 4.3571, 'SchemaType' => 'IceCreamShop']);
        OpeningException::create([
            'StoreLocationID' => $location->ID,
            'DateFrom' => '2026-12-25',
            'Type' => 'Closed',
        ])->write();

        $node = $location->StructuredData();
        $this->assertSame('IceCreamShop', $node['@type']);
        $this->assertSame('Delft', $node['address']['addressLocality']);
        $this->assertSame('Voorstraat 12a', $node['address']['streetAddress']);
        $this->assertSame(52.0116, $node['geo']['latitude']);

        $byHours = [];
        foreach ($node['openingHoursSpecification'] as $spec) {
            $byHours[$spec['opens'] . '-' . $spec['closes']] = $spec['dayOfWeek'];
        }
        $this->assertSame(['https://schema.org/Monday', 'https://schema.org/Tuesday'], $byHours['10:00-18:00']);
        $this->assertSame(['https://schema.org/Saturday'], $byHours['10:00-17:00']);

        $special = $node['specialOpeningHoursSpecification'][0];
        $this->assertSame(['2026-12-25', '2026-12-25', '00:00', '00:00'], [$special['validFrom'], $special['validThrough'], $special['opens'], $special['closes']]);

        $json = json_decode($location->StructuredDataJSON(), true);
        $this->assertSame('https://schema.org', $json['@context']);
    }

    public function testCmsFieldsBuildWithPreview(): void
    {
        $fields = $this->make()->getCMSFields();

        $this->assertInstanceOf(WeeklyHoursField::class, $fields->dataFieldByName('RegularHours'));
        $this->assertNotNull($fields->dataFieldByName('Schedules'));
        $this->assertNotNull($fields->dataFieldByName('Exceptions'));
        $preview = $fields->fieldByName('Root.Preview.HoursPreview');
        $this->assertNotNull($preview);
        $this->assertStringContainsString('store-hours-preview', (string) $preview->getContent());
    }
}
