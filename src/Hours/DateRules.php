<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Hours;

use DateTimeImmutable;

/**
 * Date matching for seasons and exceptions.
 *
 * - One-off: `from <= date <= to` on `Y-m-d`.
 * - Yearly: compares month-day only. A range may wrap the year end (`12-27` to `01-02`). A `02-29` rule only matches
 *   in leap years, because no other year has that date.
 */
final class DateRules
{
    public static function matches(string $date, string $from, string $to, bool $yearly): bool
    {
        if ($from === '') {
            return false;
        }
        $to = $to !== '' ? $to : $from;

        if (!$yearly) {
            return $from <= $date && $date <= $to;
        }

        $md = substr($date, 5, 5);
        $fromMd = substr($from, 5, 5);
        $toMd = substr($to, 5, 5);

        return $fromMd <= $toMd
            ? ($fromMd <= $md && $md <= $toMd)
            : ($md >= $fromMd || $md <= $toMd);
    }

    /**
     * Number of days a rule spans (used to prefer the most specific rule).
     */
    public static function span(string $from, string $to, bool $yearly): int
    {
        $to = $to !== '' ? $to : $from;
        if ($from === '') {
            return PHP_INT_MAX;
        }

        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        if ($yearly) {
            // Measure month-day distance in a leap reference year, wrapping the year end.
            $start = new DateTimeImmutable('2000-' . substr($from, 5, 5));
            $end = new DateTimeImmutable('2000-' . substr($to, 5, 5));
            if ($end < $start) {
                $end = $end->modify('+1 year');
            }
        }

        return (int) $start->diff($end)->days + 1;
    }

    /**
     * "25 Dec 2026", "24 Dec 2026 – 2 Jan 2027" or, yearly, "every year 25 Dec".
     */
    public static function periodText(string $from, string $to, bool $yearly): string
    {
        if ($from === '') {
            return '';
        }
        $to = $to !== '' ? $to : $from;
        $pattern = $yearly ? 'd MMM' : 'd MMM y';
        $fromText = DayHours::formatDate(new DateTimeImmutable($from), $pattern);
        $text = $from === $to
            ? $fromText
            : $fromText . ' – ' . DayHours::formatDate(new DateTimeImmutable($to), $pattern);

        return $yearly ? _t(self::class . '.EVERY_YEAR', 'every year {period}', ['period' => $text]) : $text;
    }
}
