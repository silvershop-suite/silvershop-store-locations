<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Seo;

use DateTimeImmutable;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Builds the schema.org node for a location (Store / LocalBusiness subtype): address, geo, contact,
 * openingHoursSpecification for the weekly pattern in effect today, and specialOpeningHoursSpecification for
 * upcoming exceptions. Used standalone on the detail page and by the seo-module bridge.
 */
class StoreLocationStructuredData
{
    use Injectable;
    use Configurable;

    /** How many days ahead exceptions are published as special opening hours. */
    private static int $special_hours_days = 60;

    private const SCHEMA_DAYS = [
        1 => 'https://schema.org/Monday',
        2 => 'https://schema.org/Tuesday',
        3 => 'https://schema.org/Wednesday',
        4 => 'https://schema.org/Thursday',
        5 => 'https://schema.org/Friday',
        6 => 'https://schema.org/Saturday',
        7 => 'https://schema.org/Sunday',
    ];

    /**
     * @return array<string, mixed>
     */
    public function node(StoreLocation $location, string $id = ''): array
    {
        $url = (string) $location->AbsoluteLink();
        $node = [
            '@type' => $location->SchemaType ?: 'Store',
            '@id' => $id !== '' ? $id : ($url !== '' ? $url . '#store' : null),
            'name' => (string) $location->Title,
            'url' => $url ?: null,
            'telephone' => $location->Phone ?: null,
            'email' => $location->Email ?: null,
            'description' => $location->Intro ?: null,
        ];

        if ($location->ImageID && $location->Image()->exists()) {
            $node['image'] = $location->Image()->getAbsoluteURL();
        }

        $node['address'] = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => trim($location->StreetLine() . ' ' . $location->AddressLine2) ?: null,
            'postalCode' => $location->PostalCode ?: null,
            'addressLocality' => $location->City ?: null,
            'addressRegion' => $location->Region ?: null,
            'addressCountry' => $location->CountryCode ?: null,
        ], fn ($v) => $v !== null);

        if ($location->hasCoordinates()) {
            $node['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => round((float) $location->Latitude, 6),
                'longitude' => round((float) $location->Longitude, 6),
            ];
        }

        $calendar = OpeningCalendar::singleton();
        $hours = $this->openingHoursSpecification($calendar->resolver($location)->patternFor($calendar->now($location))['week']);
        if ($hours !== []) {
            $node['openingHoursSpecification'] = $hours;
        }
        $special = $this->specialOpeningHours($location);
        if ($special !== []) {
            $node['specialOpeningHoursSpecification'] = $special;
        }

        return array_filter($node, fn ($v) => $v !== null);
    }

    /**
     * One specification per distinct range, listing every weekday that has it.
     *
     * @param array<int, array<int, array{0: string, 1: string}>> $week
     * @return array<int, array<string, mixed>>
     */
    public function openingHoursSpecification(array $week): array
    {
        $byRange = [];
        foreach ($week as $day => $ranges) {
            foreach ($ranges as $range) {
                $key = $range[0] . '-' . $range[1];
                $byRange[$key]['range'] = $range;
                $byRange[$key]['days'][] = self::SCHEMA_DAYS[$day];
            }
        }
        ksort($byRange);

        $specs = [];
        foreach ($byRange as $entry) {
            [$opens, $closes] = self::schemaTimes($entry['range']);
            $specs[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $entry['days'],
                'opens' => $opens,
                'closes' => $closes,
            ];
        }

        return $specs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function specialOpeningHours(StoreLocation $location): array
    {
        $calendar = OpeningCalendar::singleton();
        $specs = [];
        $days = (int) self::config()->get('special_hours_days');
        foreach ($calendar->upcomingExceptions($location, null, $days) as $notice) {
            $from = $notice['From'];
            $to = $notice['To'];
            $day = $calendar->day($location, new DateTimeImmutable($from, $location->getTimezoneObject()));
            $ranges = $day->getRanges() ?: [['00:00', '00:00']];
            foreach ($ranges as $range) {
                [$opens, $closes] = $range === ['00:00', '00:00'] ? ['00:00', '00:00'] : self::schemaTimes($range);
                $specs[] = [
                    '@type' => 'OpeningHoursSpecification',
                    'validFrom' => $from,
                    'validThrough' => $to,
                    'opens' => $opens,
                    'closes' => $closes,
                ];
            }
        }

        return $specs;
    }

    /**
     * schema.org has no 24:00: all day is 00:00-23:59; an overnight range keeps its (earlier) closing time.
     *
     * @param array{0: string, 1: string} $range
     * @return array{0: string, 1: string}
     */
    private static function schemaTimes(array $range): array
    {
        return [$range[0], $range[1] === '24:00' ? '23:59' : $range[1]];
    }
}
