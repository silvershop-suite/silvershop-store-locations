<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Model;

use SilverShop\StoreLocations\Hours\DateRules;
use SilverShop\StoreLocations\Hours\DayHours;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Hours\TimeRanges;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\DataObject;

/**
 * A date (or date range) on which a location is closed or has special hours, once or every year. A
 * StoreLocationID of 0 means the exception applies to all locations ("closing days").
 *
 * Every day in the range is affected, and the exception replaces the whole day (it never merges with the regular
 * hours).
 *
 * @property string|null $DateFrom
 * @property string|null $DateTo
 * @property string $Type
 * @property string $Hours canonical ranges, e.g. "10:00-16:00"
 * @property bool $RecurringYearly
 * @property string $Reason
 * @property int $StoreLocationID
 * @method StoreLocation StoreLocation()
 */
class OpeningException extends DataObject
{
    public const TYPE_CLOSED = 'Closed';
    public const TYPE_SPECIAL_HOURS = 'SpecialHours';

    private static string $table_name = 'SilverShop_StoreLocationException';

    private static array $db = [
        'DateFrom' => 'Date',
        'DateTo' => 'Date',
        'Type' => "Enum('Closed,SpecialHours','Closed')",
        'Hours' => 'Varchar',
        'RecurringYearly' => 'Boolean',
        'Reason' => 'Varchar',
    ];

    private static array $has_one = [
        'StoreLocation' => StoreLocation::class,
    ];

    private static array $indexes = [
        'DateFrom' => true,
    ];

    private static string $default_sort = '"DateFrom" ASC';

    private static array $summary_fields = [
        'PeriodText' => 'PeriodText',
        'TypeLabel' => 'TypeLabel',
        'HoursText' => 'HoursText',
        'Reason' => 'Reason',
    ];

    private static string $singular_name = 'Exception';

    private static string $plural_name = 'Exceptions';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('StoreLocationID');

        $type = $fields->dataFieldByName('Type');
        if ($type instanceof DropdownField) {
            $type->setSource(self::typeOptions());
        }
        $fields->dataFieldByName('DateTo')?->setDescription(_t(
            self::class . '.DateToDescription',
            'Leave empty for a single day. Every day in the range is affected.'
        ));
        $fields->dataFieldByName('Hours')?->setDescription(_t(
            self::class . '.HoursDescription',
            'Only for special hours, e.g. "10:00-16:00" or "09:00-12:00, 13:00-17:00".'
        ));
        $fields->dataFieldByName('RecurringYearly')?->setDescription(_t(
            self::class . '.RecurringYearlyDescription',
            'Repeat every year on the same date(s), e.g. Christmas.'
        ));
        $fields->dataFieldByName('Reason')?->setDescription(_t(
            self::class . '.ReasonDescription',
            'Shown to visitors, e.g. "Christmas" or "Closed for renovation".'
        ));

        return $fields;
    }

    public function fieldLabels($includerelations = true)
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['PeriodText'] = _t(self::class . '.PeriodText', 'Period');
        $labels['TypeLabel'] = _t(self::class . '.db_Type', 'Type');
        $labels['HoursText'] = _t(self::class . '.db_Hours', 'Hours');
        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_CLOSED => _t(self::class . '.TYPE_Closed', 'Closed'),
            self::TYPE_SPECIAL_HOURS => _t(self::class . '.TYPE_SpecialHours', 'Special hours'),
        ];
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->DateFrom) {
            $result->addFieldError('DateFrom', _t(self::class . '.DateRequired', 'Choose a date.'));
        } elseif ($this->DateTo && !$this->RecurringYearly && $this->DateTo < $this->DateFrom) {
            $result->addFieldError('DateTo', _t(self::class . '.EndBeforeStart', 'The end date is before the start date.'));
        }

        if ($this->Type === self::TYPE_SPECIAL_HOURS) {
            $ranges = TimeRanges::tryParse((string) $this->Hours);
            if ($ranges === null) {
                $result->addFieldError('Hours', _t(self::class . '.HoursInvalid', 'These hours are not valid. Use e.g. 10:00-16:00.'));
            } elseif ($ranges === []) {
                $result->addFieldError('Hours', _t(self::class . '.HoursRequired', 'Enter the special opening hours.'));
            }
        }

        return $result;
    }

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if ($this->Type === self::TYPE_CLOSED) {
            $this->Hours = '';
        } else {
            $ranges = TimeRanges::tryParse((string) $this->Hours);
            if ($ranges !== null) {
                $this->Hours = TimeRanges::toString($ranges);
            }
        }
        if ($this->DateTo && $this->DateTo === $this->DateFrom) {
            $this->DateTo = null;
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        OpeningCalendar::singleton()->reset();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function ranges(): array
    {
        return $this->Type === self::TYPE_CLOSED ? [] : (TimeRanges::tryParse((string) $this->Hours) ?? []);
    }

    public function isGlobal(): bool
    {
        return (int) $this->StoreLocationID === 0;
    }

    public function getPeriodText(): string
    {
        return DateRules::periodText((string) $this->DateFrom, (string) ($this->DateTo ?: $this->DateFrom), (bool) $this->RecurringYearly);
    }

    public function getTypeLabel(): string
    {
        return self::typeOptions()[$this->Type] ?? (string) $this->Type;
    }

    public function getHoursText(): string
    {
        $ranges = $this->ranges();
        return $ranges === [] ? _t(DayHours::class . '.CLOSED', 'Closed') : DayHours::formatRanges($ranges);
    }

    public function getTitle()
    {
        return trim($this->getPeriodText() . ($this->Reason ? ' · ' . $this->Reason : ''));
    }

    public function canView($member = null)
    {
        $location = $this->StoreLocation();
        return $this->isGlobal() || !$location->exists() ? true : $location->canView($member);
    }

    public function canEdit($member = null)
    {
        $location = $this->StoreLocation();
        return $this->isGlobal() || !$location->exists()
            ? StoreLocation::singleton()->canEdit($member)
            : $location->canEdit($member);
    }

    public function canDelete($member = null)
    {
        return $this->canEdit($member);
    }

    public function canCreate($member = null, $context = [])
    {
        return StoreLocation::singleton()->canCreate($member, $context);
    }
}
