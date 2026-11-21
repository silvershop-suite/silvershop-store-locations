<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Model;

use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Hours\DateRules;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;

/**
 * A season: a weekly pattern that replaces the location's regular week between two dates, once or every year
 * (summer hours, December late-night shopping). When seasons overlap, the highest priority wins.
 *
 * @property string $Title
 * @property string|null $ValidFrom
 * @property string|null $ValidUntil
 * @property bool $RecurringYearly
 * @property int $Priority
 * @property string $WeeklyHours
 * @property int $StoreLocationID
 * @method StoreLocation StoreLocation()
 */
class OpeningSchedule extends DataObject
{
    private static string $table_name = 'SilverShop_StoreLocationSchedule';

    private static array $db = [
        'Title' => 'Varchar',
        'ValidFrom' => 'Date',
        'ValidUntil' => 'Date',
        'RecurringYearly' => 'Boolean',
        'Priority' => 'Int',
        'WeeklyHours' => 'Text',
    ];

    private static array $has_one = [
        'StoreLocation' => StoreLocation::class,
    ];

    private static string $default_sort = '"ValidFrom" ASC';

    private static array $summary_fields = [
        'Title' => 'Title',
        'PeriodText' => 'PeriodText',
        'Priority' => 'Priority',
    ];

    private static string $singular_name = 'Season';

    private static string $plural_name = 'Seasons';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['StoreLocationID', 'WeeklyHours']);
        $fields->addFieldToTab('Root.Main', WeeklyHoursField::create(
            'WeeklyHours',
            _t(self::class . '.WeeklyHours', 'Opening hours in this season')
        ));
        $fields->dataFieldByName('RecurringYearly')?->setDescription(_t(
            self::class . '.RecurringYearlyDescription',
            'Repeat every year on the same dates (the year of the dates is then ignored).'
        ));
        $fields->dataFieldByName('Priority')?->setDescription(_t(
            self::class . '.PriorityDescription',
            'When seasons overlap, the one with the highest priority applies.'
        ));

        return $fields;
    }

    public function fieldLabels($includerelations = true)
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['PeriodText'] = _t(self::class . '.PeriodText', 'Period');
        return $labels;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(self::class . '.TitleRequired', 'Give the season a name.'));
        }
        if (!$this->ValidFrom || !$this->ValidUntil) {
            $result->addFieldError('ValidUntil', _t(self::class . '.DatesRequired', 'A season needs a start and an end date.'));
        } elseif (!$this->RecurringYearly && $this->ValidUntil < $this->ValidFrom) {
            $result->addFieldError('ValidUntil', _t(self::class . '.EndBeforeStart', 'The end date is before the start date.'));
        }
        if (WeeklyHoursField::invalidDays((string) $this->WeeklyHours) !== []) {
            $result->addFieldError('WeeklyHours', _t(self::class . '.HoursInvalid', 'Some opening hours are not valid.'));
        }

        return $result;
    }

    public function getPeriodText(): string
    {
        return DateRules::periodText((string) $this->ValidFrom, (string) $this->ValidUntil, (bool) $this->RecurringYearly);
    }

    /**
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    public function week(): array
    {
        return WeeklyHoursField::decodeToRanges((string) $this->WeeklyHours);
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        OpeningCalendar::singleton()->reset();
    }

    public function canView($member = null)
    {
        $location = $this->StoreLocation();
        return !$location->exists() || $location->canView($member);
    }

    public function canEdit($member = null)
    {
        $location = $this->StoreLocation();
        return $location->exists() ? $location->canEdit($member) : StoreLocation::singleton()->canEdit($member);
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
