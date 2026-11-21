<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Hours;

use DateTimeImmutable;
use IntlDateFormatter;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBField;

/**
 * The resolved opening hours of one location on one calendar date: the ranges that apply, and where they came
 * from (regular week, a season, an exception, or the location's status). Template-friendly.
 */
class DayHours extends ModelData
{
    public const SOURCE_REGULAR = 'regular';
    public const SOURCE_SEASON = 'season';
    public const SOURCE_EXCEPTION = 'exception';
    public const SOURCE_GLOBAL_EXCEPTION = 'global-exception';
    public const SOURCE_STATUS = 'status';

    /**
     * @param array<int, array{0: string, 1: string}> $ranges
     */
    public function __construct(
        private readonly DateTimeImmutable $date,
        private readonly array $ranges,
        private readonly string $source = self::SOURCE_REGULAR,
        private readonly string $sourceTitle = '',
        private readonly string $reason = '',
        private readonly ?DateTimeImmutable $today = null,
    ) {
        parent::__construct();
    }

    public function getDateObject(): DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function getRanges(): array
    {
        return $this->ranges;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceTitle(): string
    {
        return $this->sourceTitle;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function isClosed(): bool
    {
        return $this->ranges === [];
    }

    /**
     * Whether this day deviates from the normal pattern (an exception or a status closure).
     */
    public function isException(): bool
    {
        return in_array($this->source, [self::SOURCE_EXCEPTION, self::SOURCE_GLOBAL_EXCEPTION, self::SOURCE_STATUS], true);
    }

    public function isToday(): bool
    {
        return $this->today !== null && $this->today->format('Y-m-d') === $this->date->format('Y-m-d');
    }

    /** @return array{d: string, r: array<int, array{0: string, 1: string}>, x: string} */
    public function toClientArray(): array
    {
        return ['d' => $this->date->format('Y-m-d'), 'r' => $this->ranges, 'x' => $this->reason];
    }

    // ---- template API --------------------------------------------------------------------------------------------

    public function Date(): DBDate
    {
        /** @var DBDate $field */
        $field = DBField::create_field('Date', $this->date->format('Y-m-d'));
        return $field;
    }

    public function DayName(): string
    {
        return self::formatDate($this->date, 'EEEE');
    }

    public function ShortDayName(): string
    {
        return self::formatDate($this->date, 'EEE');
    }

    public function DateLabel(): string
    {
        return self::formatDate($this->date, 'd MMMM');
    }




    public function Reason(): string
    {
        return $this->reason;
    }

    public function SourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_SEASON => _t(self::class . '.SOURCE_SEASON', 'Season: {title}', ['title' => $this->sourceTitle]),
            self::SOURCE_EXCEPTION => _t(self::class . '.SOURCE_EXCEPTION', 'Exception'),
            self::SOURCE_GLOBAL_EXCEPTION => _t(self::class . '.SOURCE_GLOBAL_EXCEPTION', 'Closing day (all locations)'),
            self::SOURCE_STATUS => _t(self::class . '.SOURCE_STATUS', 'Location status'),
            default => _t(self::class . '.SOURCE_REGULAR', 'Regular hours'),
        };
    }

    /**
     * "09:00 – 12:00, 13:00 – 17:30" or "Closed".
     */
    public function Text(): string
    {
        if ($this->isClosed()) {
            return _t(self::class . '.CLOSED', 'Closed');
        }

        return self::formatRanges($this->ranges);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $ranges
     */
    public static function formatRanges(array $ranges): string
    {
        if ($ranges === [['00:00', '24:00']]) {
            return _t(self::class . '.ALL_DAY', 'Open 24 hours');
        }

        return implode(', ', array_map(
            fn (array $r) => self::formatTime($r[0]) . ' – ' . self::formatTime($r[1]),
            $ranges
        ));
    }

    /**
     * Locale-aware short time ("09:00" in nl_NL, "9:00 AM" in en_US); 24:00 stays 24:00 in 24h locales.
     */
    public static function formatTime(string $time): string
    {
        $locale = i18n::get_locale();
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, 'UTC');
        $isTwelveHour = str_contains((string) $formatter->getPattern(), 'a');

        if ($time === '24:00') {
            return $isTwelveHour ? (string) $formatter->format(new DateTimeImmutable('2000-01-01 00:00:00 UTC')) : '24:00';
        }

        return (string) $formatter->format(new DateTimeImmutable('2000-01-01 ' . $time . ':00 UTC'));
    }

    public static function formatDate(DateTimeImmutable $date, string $pattern): string
    {
        $formatter = new IntlDateFormatter(
            i18n::get_locale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            null,
            $pattern
        );

        return (string) $formatter->format($date);
    }
}
