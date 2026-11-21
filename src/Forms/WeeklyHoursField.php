<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Forms;

use DateTimeImmutable;
use InvalidArgumentException;
use SilverShop\StoreLocations\Hours\DayHours;
use SilverShop\StoreLocations\Hours\TimeRanges;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Edits a week of opening hours as seven text inputs ("09:00-12:00, 13:00-17:30"; empty = closed).
 *
 * Stored as JSON: ISO weekday (1 = Monday) => canonical range string. Typed text survives a failed validation so
 * the editor can correct it.
 */
class WeeklyHoursField extends FormField
{
    /** @var array<int, string> raw text per ISO weekday */
    private array $days = [];

    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;

    public function __construct($name, $title = null, $value = null)
    {
        parent::__construct($name, $title, $value);
        $this->setDescription(_t(
            self::class . '.Description',
            'Per day, e.g. "09:00-17:30" or "09:00-12:00, 13:00-17:00". Empty means closed. A closing time before '
            . 'the opening time runs past midnight; 00:00-24:00 is open all day.'
        ));
    }

    public function setValue($value, $data = null)
    {
        $this->days = is_array($value) ? $this->normaliseInput($value) : self::decode((string) $value);
        $this->value = $this->dataValue();

        return $this;
    }

    public function setSubmittedValue($value, $data = null)
    {
        return $this->setValue(is_array($value) ? $value : [], $data);
    }

    public function dataValue()
    {
        $out = [];
        foreach (range(1, 7) as $day) {
            $text = $this->days[$day] ?? '';
            $ranges = TimeRanges::tryParse($text);
            $out[(string) $day] = $ranges === null ? $text : TimeRanges::toString($ranges);
        }

        return (string) json_encode($out);
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        foreach (range(1, 7) as $day) {
            try {
                TimeRanges::parse($this->days[$day] ?? '');
            } catch (InvalidArgumentException $e) {
                $result->addFieldError($this->getName(), self::dayName($day) . ': ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Rows for the template: day label, input name and the current text.
     *
     * @return ArrayList<ArrayData>
     */
    public function Days(): ArrayList
    {
        $rows = ArrayList::create();
        foreach (range(1, 7) as $day) {
            $rows->push(ArrayData::create([
                'Day' => $day,
                'Label' => self::dayName($day),
                'InputName' => $this->getName() . '[' . $day . ']',
                'InputID' => $this->ID() . '_' . $day,
                'Value' => $this->days[$day] ?? '',
            ]));
        }

        return $rows;
    }

    public function Type()
    {
        return 'weeklyhours text';
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int, string>
     */
    private function normaliseInput(array $value): array
    {
        $days = [];
        foreach (range(1, 7) as $day) {
            $days[$day] = trim((string) ($value[$day] ?? $value[(string) $day] ?? ''));
        }

        return $days;
    }

    // ---- static helpers shared with the models ---------------------------------------------------------------------

    /**
     * JSON (or empty) to raw text per ISO weekday 1-7.
     *
     * @return array<int, string>
     */
    public static function decode(string $json): array
    {
        $data = $json !== '' ? json_decode($json, true) : [];
        $days = [];
        foreach (range(1, 7) as $day) {
            $value = is_array($data) ? ($data[(string) $day] ?? $data[$day] ?? '') : '';
            $days[$day] = is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
        }

        return $days;
    }

    /**
     * JSON to canonical ranges per ISO weekday 1-7 (invalid days count as closed).
     *
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    public static function decodeToRanges(string $json): array
    {
        $week = [];
        foreach (self::decode($json) as $day => $text) {
            $week[$day] = TimeRanges::tryParse($text) ?? [];
        }

        return $week;
    }

    /**
     * ISO weekdays whose stored text does not parse.
     *
     * @return int[]
     */
    public static function invalidDays(string $json): array
    {
        $invalid = [];
        foreach (self::decode($json) as $day => $text) {
            if (TimeRanges::tryParse($text) === null) {
                $invalid[] = $day;
            }
        }

        return $invalid;
    }

    /**
     * Encode canonical text per weekday (helper for code and tests).
     *
     * @param array<int, string> $days ISO weekday => range text
     */
    public static function encode(array $days): string
    {
        $out = [];
        foreach (range(1, 7) as $day) {
            $ranges = TimeRanges::parse($days[$day] ?? '');
            $out[(string) $day] = TimeRanges::toString($ranges);
        }

        return (string) json_encode($out);
    }

    public static function dayName(int $isoDay): string
    {
        return DayHours::formatDate(new DateTimeImmutable('2024-01-0' . $isoDay), 'EEEE');
    }
}
