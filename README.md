# silvershop-suite/store-locations

[![CI](https://github.com/silvershop-suite/silvershop-store-locations/actions/workflows/ci.yml/badge.svg)](https://github.com/silvershop-suite/silvershop-store-locations/actions/workflows/ci.yml)

Physical store locations for Silverstripe CMS 6: per location an address, contact details and coordinates,
**regular and seasonal opening hours with exceptions** (holidays, closures, special hours), an "open now" status,
and a store list with a detail page per location, including LocalBusiness JSON-LD for search engines.

Part of the SilverShop suite, but it does **not** need SilverShop: shop integrations (pickup per location, closed
days in delivery scheduling) attach through optional bridges.

## Features

- **Locations** with address (per-country formatting via `silvershop-suite/address-formats` when installed),
  phone, email, contact link, image, intro, content, features (parking, wheelchair access, ...), coordinates,
  timezone and a schema.org business type.
- **Status:** open, temporarily closed (until a date), coming soon (opens on a date), permanently closed.
- **Opening hours:**
  - a regular week, several ranges per day ("09:00-12:00, 13:00-17:30"), ranges past midnight ("22:00-02:00"),
    open all day ("00:00-24:00");
  - **seasons** that replace the week between two dates, once or every year, with a priority when they overlap;
  - **exceptions** per location or for all locations: closed or special hours, a single day or a range (every day
    in it), once or every year, with a reason shown to visitors.
- **Clear precedence**, first match wins and replaces the whole day: location status, location exception,
  all-locations exception, season, regular week.
- **Preview in the CMS:** the next 14 days exactly as visitors will see them, with the source of each day.
- **Front end:** a store locator page listing all locations, a detail page per location (`/stores/delft`), this
  week's hours with exceptions applied, upcoming deviating hours, a grouped summary ("Mon to Fri: 10:00 – 18:00"),
  an "open now" badge, a directions link and a footer include.
- **Cache-safe status:** pages embed the next days' hours and a tiny script recomputes "Open · closes at 18:00" in
  the location's timezone, so cached pages never show a stale status.
- **JSON-LD:** `Store` (or any LocalBusiness subtype) with address, geo, `openingHoursSpecification` and
  `specialOpeningHoursSpecification`; added to the `silvershop-suite/seo` graph when installed, otherwise emitted
  standalone on the detail page.

## Installation

```bash
composer require silvershop-suite/store-locations
```

Run `dev/build?flush=all`, add locations under **Locations** in the CMS and create a **Store locator page**.

## Opening hours API

Other code asks the `OpeningCalendar` service; every method works in the location's own timezone:

```php
use SilverShop\StoreLocations\Hours\OpeningCalendar;

$calendar = OpeningCalendar::singleton();
$calendar->isOpenAt($location);                       // now, or pass any DateTimeInterface
$calendar->statusAt($location)->Text();               // "Open · closes at 18:00"
$calendar->nextOpen($location);                       // ?DateTimeImmutable
$calendar->day($location, $date)->getRanges();        // [['10:00', '18:00']]
$calendar->days($location, null, 7);                  // this week, exceptions applied
$calendar->closedDates($location, $from, $to);        // ['2026-12-25', ...]
$calendar->workingDaysAfter($location, $date, 3);     // e.g. earliest pickup date
$calendar->summary($location);                        // grouped weekly lines
$calendar->upcomingExceptions($location, null, 60);   // merged notices
```

The time arithmetic (ranges past midnight, next open/close, timezone conversion) is done by
[`spatie/opening-hours`](https://github.com/spatie/opening-hours); which hours apply on a date is decided by this
module.

## Templates

| Variable / include | What it renders |
|---|---|
| `$StoreLocations` | all listed locations (permanently closed ones excluded) |
| `$PrimaryStoreLocation` | the location marked as main location, else the first |
| `<% include SilverShop\StoreLocations\Includes\LocationsFooter %>` | name, address, today's hours and status per location |
| `<% include SilverShop\StoreLocations\Includes\OpeningHoursTable %>` | the coming week (location scope) |
| `<% include SilverShop\StoreLocations\Includes\HoursSummary %>` | grouped regular hours (location scope) |
| `<% include SilverShop\StoreLocations\Includes\OpeningStatus %>` | the "open now" badge (location scope) |

On a location: `$FormattedAddress`, `$TodayHours.Text`, `$OpeningStatus.Text`, `$Week`, `$HoursSummary`,
`$UpcomingExceptions`, `$DirectionsLink`, `$PhoneLink`, `$Link`.

## Configuration

```yaml
SilverShop\StoreLocations\Model\StoreLocation:
  default_country: 'NL'
  default_timezone: ''          # empty = PHP default
  directions_provider: 'google' # google | apple | osm
  show_country_in_address: false

SilverShop\StoreLocations\Hours\OpeningCalendar:
  closing_soon_minutes: 30
  opening_soon_minutes: 30

SilverShop\StoreLocations\Control\StoreLocatorPageController:
  include_css: true             # small theme-neutral stylesheet
  include_js: true              # cache-safe "open now" script
  emit_jsonld: true             # standalone JSON-LD when the seo module is absent
```

## Optional integrations

| Module | Effect |
|---|---|
| `silvershop-suite/address-formats` | per-country address formatting |
| `silvershop-suite/seo` | locations join the site's JSON-LD graph (Store node, ItemList on the locator page) |
| `tractorcow/silverstripe-fluent` | translatable names, texts and exception reasons |
| `silvershop-suite/cms-menu` | icon in the grouped CMS menu |
| `symbiote/silverstripe-gridfieldextensions` | drag-and-drop ordering of locations |

Planned: geocoding and a consent-gated map, a store locator search ("near me", postcode), public holiday presets,
and bridges for click-and-collect (pickup per location) and delivery-scheduling (closed days).

## License

BSD-3-Clause. See [LICENSE](LICENSE). Uses `spatie/opening-hours` (MIT).

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
