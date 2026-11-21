<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Model;

use DateTimeImmutable;
use DateTimeZone;
use SilverShop\StoreLocations\Admin\StoreLocationAdmin;
use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Hours\DayHours;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Hours\OpeningStatus;
use SilverShop\StoreLocations\Hours\TimeRanges;
use SilverShop\StoreLocations\Page\StoreLocatorPage;
use SilverShop\StoreLocations\Seo\StoreLocationStructuredData;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\i18n\Data\Intl\IntlLocales;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Permission;
use SilverStripe\View\Parsers\URLSegmentFilter;

/**
 * A physical store / branch: address, contact, coordinates, opening hours (regular week on the record, seasons and
 * exceptions as related rows) and a status. The hours logic lives in {@see OpeningCalendar}; this class exposes it
 * to templates.
 *
 * @property string $Title
 * @property string $URLSegment
 * @property int $Sort
 * @property bool $IsPrimary
 * @property string $Status
 * @property string|null $StatusUntil
 * @property string|null $OpensOn
 * @property string $StatusMessage
 * @property string $Intro
 * @property string $Content
 * @property string $Street
 * @property string $HouseNumber
 * @property string $AddressLine2
 * @property string $PostalCode
 * @property string $City
 * @property string $Region
 * @property string $CountryCode
 * @property string $Phone
 * @property string $Email
 * @property string $ContactUrl
 * @property float|string|null $Latitude
 * @property float|string|null $Longitude
 * @property string $GeoSource
 * @property string $Timezone
 * @property string $SchemaType
 * @property string $RegularHours JSON: ISO weekday (1-7) => canonical range string
 * @property int $ImageID
 * @method Image Image()
 * @method HasManyList<OpeningSchedule> Schedules()
 * @method HasManyList<OpeningException> Exceptions()
 * @method ManyManyList<LocationFeature> Features()
 */
class StoreLocation extends DataObject
{
    public const STATUS_OPEN = 'Open';
    public const STATUS_TEMPORARILY_CLOSED = 'TemporarilyClosed';
    public const STATUS_COMING_SOON = 'ComingSoon';
    public const STATUS_PERMANENTLY_CLOSED = 'PermanentlyClosed';

    private static string $table_name = 'SilverShop_StoreLocation';

    private static array $db = [
        'Title' => 'Varchar',
        'URLSegment' => 'Varchar',
        'Sort' => 'Int',
        'IsPrimary' => 'Boolean',
        'Status' => "Enum('Open,TemporarilyClosed,ComingSoon,PermanentlyClosed','Open')",
        'StatusUntil' => 'Date',
        'OpensOn' => 'Date',
        'StatusMessage' => 'Varchar',
        'Intro' => 'Text',
        'Content' => 'HTMLText',
        'Street' => 'Varchar',
        'HouseNumber' => 'Varchar(20)',
        'AddressLine2' => 'Varchar',
        'PostalCode' => 'Varchar(20)',
        'City' => 'Varchar',
        'Region' => 'Varchar',
        'CountryCode' => 'Varchar(2)',
        'Phone' => 'Varchar(50)',
        'Email' => 'Varchar',
        'ContactUrl' => 'Varchar',
        'Latitude' => 'Decimal(10,8)',
        'Longitude' => 'Decimal(11,8)',
        'GeoSource' => "Enum('None,Manual,Geocoded','None')",
        'Timezone' => 'Varchar(64)',
        'SchemaType' => 'Varchar(64)',
        'RegularHours' => 'Text',
    ];

    private static array $has_one = [
        'Image' => Image::class,
    ];

    private static array $owns = [
        'Image',
    ];

    private static array $has_many = [
        'Schedules' => OpeningSchedule::class,
        'Exceptions' => OpeningException::class,
    ];

    private static array $many_many = [
        'Features' => LocationFeature::class,
    ];

    private static array $cascade_deletes = [
        'Schedules',
        'Exceptions',
    ];

    private static array $indexes = [
        'URLSegment' => true,
        'Sort' => true,
    ];

    private static array $defaults = [
        'Status' => self::STATUS_OPEN,
        'GeoSource' => 'None',
        'SchemaType' => 'Store',
    ];

    private static string $default_sort = '"Sort" ASC, "Title" ASC';

    private static array $summary_fields = [
        'Title' => 'Title',
        'City' => 'City',
        'StatusLabel' => 'StatusLabel',
        'TodayText' => 'TodayText',
    ];

    private static array $searchable_fields = [
        'Title',
        'City',
        'Status',
    ];

    private static string $singular_name = 'Location';

    private static string $plural_name = 'Locations';

    /**
     * Default country for new locations (ISO-2).
     */
    private static string $default_country = 'NL';

    /**
     * Timezone used when a location has none (empty = the PHP default timezone).
     */
    private static string $default_timezone = '';

    /**
     * Which service the "route" link opens: google, apple or osm.
     */
    private static string $directions_provider = 'google';

    /**
     * Show the country line in the formatted address (off for shops with only domestic locations).
     */
    private static bool $show_country_in_address = false;

    /**
     * Schema.org types offered in the CMS for {@see self::$SchemaType}.
     *
     * @var string[]
     */
    private static array $schema_types = [
        'Store',
        'LocalBusiness',
        'IceCreamShop',
        'CafeOrCoffeeShop',
        'Bakery',
        'FoodEstablishment',
        'ClothingStore',
        'BookStore',
        'FurnitureStore',
        'GardenStore',
        'HardwareStore',
        'HomeGoodsStore',
        'JewelryStore',
        'ShoeStore',
        'SportingGoodsStore',
        'ToyStore',
        'Optician',
        'Pharmacy',
    ];

    public function populateDefaults()
    {
        parent::populateDefaults();
        $this->CountryCode = (string) self::config()->get('default_country');
        return $this;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->removeByName(['Sort', 'GeoSource', 'Schedules', 'Exceptions', 'Features']);

        $fields->dataFieldByName('URLSegment')?->setDescription(_t(
            self::class . '.URLSegmentDescription',
            'Used in the address of the location page. Generated from the title when empty.'
        ));
        $fields->dataFieldByName('IsPrimary')?->setDescription(_t(
            self::class . '.IsPrimaryDescription',
            'The main location, used where a site shows a single store (e.g. a footer).'
        ));

        $status = $fields->dataFieldByName('Status');
        if ($status instanceof DropdownField) {
            $status->setSource(self::statusOptions());
        }
        $fields->dataFieldByName('StatusUntil')?->setDescription(_t(
            self::class . '.StatusUntilDescription',
            'Temporarily closed: last closed day. Empty = until further notice.'
        ));
        $fields->dataFieldByName('OpensOn')?->setDescription(_t(
            self::class . '.OpensOnDescription',
            'Coming soon: the opening date.'
        ));

        $schemaTypes = (array) self::config()->get('schema_types');
        $fields->replaceField('SchemaType', DropdownField::create(
            'SchemaType',
            $this->fieldLabel('SchemaType'),
            array_combine($schemaTypes, $schemaTypes)
        )->setDescription(_t(
            self::class . '.SchemaTypeDescription',
            'The schema.org business type used for search engines.'
        )));

        // Address + coordinates.
        $addressFields = ['Street', 'HouseNumber', 'AddressLine2', 'PostalCode', 'City', 'Region', 'CountryCode'];
        $fields->replaceField('CountryCode', DropdownField::create(
            'CountryCode',
            $this->fieldLabel('CountryCode'),
            self::countryOptions()
        ));
        $contactFields = ['Phone', 'Email', 'ContactUrl'];
        $geoFields = ['Latitude', 'Longitude', 'Timezone'];
        $fields->replaceField('Timezone', DropdownField::create(
            'Timezone',
            $this->fieldLabel('Timezone'),
            array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())
        )->setEmptyString(_t(self::class . '.TimezoneDefault', 'Site default ({tz})', ['tz' => self::defaultTimezoneName()])));
        $fields->dataFieldByName('Latitude')?->setDescription(_t(
            self::class . '.CoordinatesDescription',
            'Decimal degrees, e.g. 52.0116 / 4.3571. Used for maps and distance sorting.'
        ));

        $this->moveFields($fields, 'Root.Address', array_merge($addressFields, $geoFields));
        $this->moveFields($fields, 'Root.Contact', $contactFields);

        // Opening hours: the regular week inline, seasons below it.
        $fields->removeByName('RegularHours');
        $fields->addFieldsToTab('Root.OpeningHours', [
            WeeklyHoursField::create('RegularHours', _t(self::class . '.RegularHours', 'Regular opening hours')),
            HeaderField::create('SeasonsHeader', _t(self::class . '.Seasons', 'Seasonal hours'), 3),
        ]);
        if ($this->isInDB()) {
            $fields->addFieldsToTab('Root.OpeningHours', [
                GridField::create('Schedules', '', $this->Schedules(), GridFieldConfig_RecordEditor::create())
                    ->setDescription(_t(
                        self::class . '.SeasonsDescription',
                        'A season replaces the regular week between two dates (optionally every year).'
                    )),
            ]);
            $fields->addFieldsToTab('Root.Exceptions', [
                GridField::create('Exceptions', '', $this->Exceptions(), GridFieldConfig_RecordEditor::create())
                    ->setDescription(_t(
                        self::class . '.ExceptionsDescription',
                        'Closures and special hours on specific dates. Closing days for all locations are managed '
                        . 'under "Closing days" in the Locations admin.'
                    )),
            ]);
            $fields->addFieldToTab('Root.Main', GridField::create(
                'Features',
                $this->fieldLabel('Features'),
                $this->Features(),
                GridFieldConfig_RecordEditor::create()
            ));
            $fields->addFieldToTab('Root.Preview', LiteralField::create('HoursPreview', $this->renderPreview()));
        } else {
            $fields->addFieldToTab('Root.OpeningHours', LiteralField::create('SaveFirst', '<p class="message notice">'
                . _t(self::class . '.SaveFirst', 'Save the location first to add seasons, exceptions and features.')
                . '</p>'));
        }

        foreach (['OpeningHours', 'Exceptions', 'Address', 'Contact', 'Preview'] as $tab) {
            $fields->fieldByName('Root.' . $tab)?->setTitle(_t(self::class . '.Tab' . $tab, $this->tabTitle($tab)));
        }

        return $fields;
    }

    /**
     * @param string[] $names
     */
    private function moveFields(FieldList $fields, string $tab, array $names): void
    {
        foreach ($names as $name) {
            $field = $fields->dataFieldByName($name);
            if ($field) {
                $fields->removeByName($name);
                $fields->addFieldToTab($tab, $field);
            }
        }
    }

    private function tabTitle(string $tab): string
    {
        return match ($tab) {
            'OpeningHours' => 'Opening hours',
            'Preview' => 'Preview (next 14 days)',
            default => $tab,
        };
    }

    public function fieldLabels($includerelations = true)
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['StatusLabel'] = _t(self::class . '.db_Status', 'Status');
        $labels['TodayText'] = _t(self::class . '.TodayText', 'Today');
        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_OPEN => _t(self::class . '.STATUS_Open', 'Open'),
            self::STATUS_TEMPORARILY_CLOSED => _t(self::class . '.STATUS_TemporarilyClosed', 'Temporarily closed'),
            self::STATUS_COMING_SOON => _t(self::class . '.STATUS_ComingSoon', 'Coming soon'),
            self::STATUS_PERMANENTLY_CLOSED => _t(self::class . '.STATUS_PermanentlyClosed', 'Permanently closed'),
        ];
    }

    /**
     * Country dropdown source keyed by upper-case ISO-2 code.
     *
     * @return array<string, string>
     */
    public static function countryOptions(): array
    {
        $countries = IntlLocales::singleton()->getCountries();
        return array_combine(array_map('strtoupper', array_keys($countries)), array_values($countries));
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->Status] ?? (string) $this->Status;
    }

    public function getTodayText(): string
    {
        return $this->isInDB() ? $this->TodayHours()->Text() : '';
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(self::class . '.TitleRequired', 'A location needs a title.'));
        }
        if ($this->Email && !filter_var($this->Email, FILTER_VALIDATE_EMAIL)) {
            $result->addFieldError('Email', _t(self::class . '.EmailInvalid', 'This is not a valid email address.'));
        }
        if ($this->CountryCode && !preg_match('/^[A-Z]{2}$/', (string) $this->CountryCode)) {
            $result->addFieldError('CountryCode', _t(self::class . '.CountryInvalid', 'Use a two-letter country code.'));
        }
        if ($this->Timezone && !in_array($this->Timezone, DateTimeZone::listIdentifiers(), true)) {
            $result->addFieldError('Timezone', _t(self::class . '.TimezoneInvalid', 'Unknown timezone.'));
        }
        $lat = $this->Latitude;
        $lng = $this->Longitude;
        if ($lat !== null && $lat !== '' && (abs((float) $lat) > 90)) {
            $result->addFieldError('Latitude', _t(self::class . '.LatitudeInvalid', 'Latitude must be between -90 and 90.'));
        }
        if ($lng !== null && $lng !== '' && (abs((float) $lng) > 180)) {
            $result->addFieldError('Longitude', _t(self::class . '.LongitudeInvalid', 'Longitude must be between -180 and 180.'));
        }
        foreach (WeeklyHoursField::decode((string) $this->RegularHours) as $day => $text) {
            if (TimeRanges::tryParse($text) === null) {
                $result->addFieldError('RegularHours', _t(
                    self::class . '.RegularHoursInvalid',
                    'The opening hours for day {day} are not valid.',
                    ['day' => $day]
                ));
            }
        }

        return $result;
    }

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $this->URLSegment = $this->generateUniqueURLSegment((string) ($this->URLSegment ?: $this->Title));

        if ($this->isChanged('Latitude') || $this->isChanged('Longitude')) {
            $this->GeoSource = $this->hasCoordinates() ? 'Manual' : 'None';
        }

        if ($this->IsPrimary && $this->isChanged('IsPrimary')) {
            foreach (self::get()->filter('IsPrimary', true)->exclude('ID', (int) $this->ID) as $other) {
                $other->IsPrimary = false;
                $other->write();
            }
        }
    }

    private function generateUniqueURLSegment(string $source): string
    {
        $base = URLSegmentFilter::create()->filter($source) ?: 'location';
        $segment = $base;
        $i = 2;
        while (self::get()->filter('URLSegment', $segment)->exclude('ID', (int) $this->ID)->exists()) {
            $segment = $base . '-' . $i++;
        }

        return $segment;
    }

    // ---- status ---------------------------------------------------------------------------------------------------

    /**
     * The status that applies on a date: a temporary closure ends after StatusUntil, "coming soon" ends on OpensOn.
     */
    public function effectiveStatusOn(DateTimeImmutable $date): string
    {
        $day = $date->format('Y-m-d');

        return match ($this->Status) {
            self::STATUS_TEMPORARILY_CLOSED => ($this->StatusUntil && $day > $this->StatusUntil)
                ? self::STATUS_OPEN : self::STATUS_TEMPORARILY_CLOSED,
            self::STATUS_COMING_SOON => ($this->OpensOn && $day >= $this->OpensOn)
                ? self::STATUS_OPEN : self::STATUS_COMING_SOON,
            self::STATUS_PERMANENTLY_CLOSED => self::STATUS_PERMANENTLY_CLOSED,
            default => self::STATUS_OPEN,
        };
    }

    public function isListed(): bool
    {
        return $this->Status !== self::STATUS_PERMANENTLY_CLOSED;
    }

    // ---- time -----------------------------------------------------------------------------------------------------

    public function getTimezoneObject(): DateTimeZone
    {
        $name = (string) $this->Timezone;
        if ($name === '' || !in_array($name, DateTimeZone::listIdentifiers(), true)) {
            $name = self::defaultTimezoneName();
        }

        return new DateTimeZone($name);
    }

    public static function defaultTimezoneName(): string
    {
        $configured = (string) self::config()->get('default_timezone');
        return $configured !== '' ? $configured : date_default_timezone_get();
    }

    /**
     * Regular weekly hours: ISO weekday (1 = Monday) => canonical ranges.
     *
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    public function regularWeek(): array
    {
        return WeeklyHoursField::decodeToRanges((string) $this->RegularHours);
    }

    // ---- address --------------------------------------------------------------------------------------------------

    /**
     * Address in the array shape silvershop-suite/address-formats expects.
     *
     * @return array<string, string>
     */
    public function AddressData(): array
    {
        $data = [
            'Name' => '',
            'Company' => '',
            'Address' => (string) $this->Street,
            'HouseNumber' => (string) $this->HouseNumber,
            'AddressLine2' => (string) $this->AddressLine2,
            'PostalCode' => (string) $this->PostalCode,
            'City' => (string) $this->City,
            'State' => (string) $this->Region,
            'Country' => '',
        ];
        if (self::config()->get('show_country_in_address') && $this->CountryCode) {
            $data['Country'] = (string) IntlLocales::singleton()->countryName(strtolower((string) $this->CountryCode));
        }

        return $data;
    }

    /**
     * Street + house number on one line (for cards and structured data).
     */
    public function StreetLine(): string
    {
        return trim($this->Street . ' ' . $this->HouseNumber);
    }

    /**
     * Address lines, formatted per country when address-formats is installed.
     *
     * @return string[]
     */
    public function addressLines(): array
    {
        $data = $this->AddressData();
        $formatter = 'SilverShop\\AddressFormats\\AddressFormatter';
        if (class_exists($formatter)) {
            return (array) Injector::inst()->get($formatter)->lines($data, $this->CountryCode ?: null);
        }

        return array_values(array_filter([
            $this->StreetLine(),
            (string) $this->AddressLine2,
            trim($this->PostalCode . ' ' . $this->City),
            (string) $this->Region,
            $data['Country'],
        ], fn ($line) => $line !== ''));
    }

    public function FormattedAddress(): DBHTMLText
    {
        $html = implode('<br>', array_map(fn ($l) => htmlspecialchars($l, ENT_QUOTES), $this->addressLines()));
        /** @var DBHTMLText $field */
        $field = DBHTMLText::create_field('HTMLFragment', $html);
        return $field;
    }

    public function AddressOneLine(): string
    {
        return implode(', ', $this->addressLines());
    }

    public function hasCoordinates(): bool
    {
        return $this->Latitude !== null && $this->Latitude !== '' && $this->Longitude !== null
            && $this->Longitude !== '' && !((float) $this->Latitude === 0.0 && (float) $this->Longitude === 0.0);
    }

    public function DirectionsLink(): string
    {
        $destination = $this->hasCoordinates()
            ? sprintf('%.6F,%.6F', (float) $this->Latitude, (float) $this->Longitude)
            : $this->AddressOneLine();
        $encoded = rawurlencode($destination);

        return match ((string) self::config()->get('directions_provider')) {
            'apple' => 'https://maps.apple.com/?daddr=' . $encoded,
            'osm' => $this->hasCoordinates()
                ? 'https://www.openstreetmap.org/directions?route=%3B' . $encoded
                : 'https://www.openstreetmap.org/search?query=' . $encoded,
            default => 'https://www.google.com/maps/dir/?api=1&destination=' . $encoded,
        };
    }

    /**
     * Phone number for a tel: link (digits and a leading +).
     */
    public function PhoneLink(): string
    {
        $number = preg_replace('/[^0-9+]/', '', (string) $this->Phone);
        return $number ? 'tel:' . $number : '';
    }

    // ---- links ----------------------------------------------------------------------------------------------------

    public function Link($action = null): ?string
    {
        $page = StoreLocatorPage::get()->first();
        if (!$page) {
            return null;
        }

        return $page->Link($this->URLSegment . ($action ? '/' . $action : ''));
    }

    public function AbsoluteLink($action = null): ?string
    {
        $link = $this->Link($action);
        return $link ? Director::absoluteURL($link) : null;
    }

    // ---- opening hours for templates ------------------------------------------------------------------------------

    private function calendar(): OpeningCalendar
    {
        return OpeningCalendar::singleton();
    }

    public function OpeningStatus(): OpeningStatus
    {
        return $this->calendar()->statusAt($this);
    }

    public function IsOpenNow(): bool
    {
        return $this->calendar()->isOpenAt($this);
    }

    public function TodayHours(): DayHours
    {
        return $this->calendar()->day($this, $this->calendar()->now($this));
    }

    /**
     * The next seven days (from today), exceptions applied.
     *
     * @return ArrayList<DayHours>
     */
    public function Week(): ArrayList
    {
        return ArrayList::create($this->calendar()->days($this, null, 7));
    }

    /**
     * Grouped summary of the schedule in effect today ("Mon to Fri: 09:00 – 17:00").
     *
     * @return ArrayList<ArrayData>
     */
    public function HoursSummary(): ArrayList
    {
        return ArrayList::create(array_map(
            fn (array $line) => ArrayData::create($line),
            $this->calendar()->summary($this)
        ));
    }

    /**
     * Exceptions and closures in the coming weeks, consecutive days merged.
     *
     * @return ArrayList<ArrayData>
     */
    public function UpcomingExceptions(int $days = 60): ArrayList
    {
        return ArrayList::create(array_map(
            fn (array $notice) => ArrayData::create($notice),
            $this->calendar()->upcomingExceptions($this, null, $days)
        ));
    }

    /**
     * JSON the status script uses to keep "open now" correct on cached pages.
     */
    public function StatusJSON(): string
    {
        return (string) json_encode($this->calendar()->clientData($this), JSON_UNESCAPED_UNICODE);
    }

    public function StructuredData(): array
    {
        return StoreLocationStructuredData::singleton()->node($this);
    }

    public function StructuredDataJSON(): string
    {
        $data = ['@context' => 'https://schema.org'] + $this->StructuredData();
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function renderPreview(): string
    {
        return (string) $this->customise([
            'Days' => ArrayList::create($this->calendar()->days($this, null, 14)),
        ])->renderWith('SilverShop\\StoreLocations\\Admin\\HoursPreview');
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        OpeningCalendar::singleton()->reset();
    }

    // ---- permissions: public to view, editable with access to the Locations admin --------------------------------

    public function canView($member = null)
    {
        return true;
    }

    public function canEdit($member = null)
    {
        return Permission::check('CMS_ACCESS_' . StoreLocationAdmin::class, 'any', $member);
    }

    public function canDelete($member = null)
    {
        return $this->canEdit($member);
    }

    public function canCreate($member = null, $context = [])
    {
        return $this->canEdit($member);
    }
}
