<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Hours;

use InvalidArgumentException;

/**
 * Parsing, validation and normalisation of a day's opening time ranges.
 *
 * Canonical form is a list of `[start, end]` pairs of `HH:MM` strings, sorted by start. Editors type them as text
 * ("9-12, 13:00-17:30"), so parsing is forgiving about separators and dashes but strict about the result:
 *
 * - `24:00` is only valid as an end time; `00:00-24:00` means open all day.
 * - An end at or before the start means the range runs past midnight ("22:00-02:00" = until 02:00 the next day).
 * - `00:00-00:00` is rejected as ambiguous (use `00:00-24:00` for all day, or an empty day for closed).
 * - Ranges in one day may not overlap (an overnight range is measured on a 0-48h axis).
 */
final class TimeRanges
{
    /** Words an editor may type to mean "closed" (matched case-insensitively). */
    private const CLOSED_WORDS = ['closed', 'gesloten', 'geschlossen', 'fermé', 'ferme', '-'];

    /**
     * Parse editor input (or an already-canonical list) into canonical ranges.
     *
     * @param string|array<int, mixed>|null $input
     * @return array<int, array{0: string, 1: string}>
     * @throws InvalidArgumentException with an editor-readable message
     */
    public static function parse(string|array|null $input): array
    {
        if ($input === null) {
            return [];
        }

        $parts = is_array($input) ? $input : preg_split('/[,;\n]+/', $input);
        $ranges = [];
        foreach ((array) $parts as $part) {
            if (is_array($part)) {
                $part = implode('-', $part);
            }
            $part = trim((string) $part);
            if ($part === '' || in_array(mb_strtolower($part), self::CLOSED_WORDS, true)) {
                continue;
            }
            $ranges[] = self::parseRange($part);
        }

        usort($ranges, fn (array $a, array $b) => strcmp($a[0], $b[0]));
        self::assertNoOverlap($ranges);

        return $ranges;
    }

    /**
     * Like {@see parse()}, but returns null instead of throwing.
     *
     * @param string|array<int, mixed>|null $input
     * @return array<int, array{0: string, 1: string}>|null
     */
    public static function tryParse(string|array|null $input): ?array
    {
        try {
            return self::parse($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Canonical storage string, e.g. "09:00-12:00,13:00-17:30" (empty string = closed).
     *
     * @param array<int, array{0: string, 1: string}> $ranges
     */
    public static function toString(array $ranges): string
    {
        return implode(',', array_map(fn (array $r) => $r[0] . '-' . $r[1], $ranges));
    }

    /**
     * Ranges in the string form spatie/opening-hours accepts.
     *
     * @param array<int, array{0: string, 1: string}> $ranges
     * @return string[]
     */
    public static function toSpatie(array $ranges): array
    {
        return array_map(fn (array $r) => $r[0] . '-' . $r[1], $ranges);
    }

    /**
     * Whether a range runs past midnight into the next day.
     *
     * @param array{0: string, 1: string} $range
     */
    public static function isOvernight(array $range): bool
    {
        return $range[1] !== '24:00' && self::minutes($range[1]) <= self::minutes($range[0]);
    }

    /**
     * Minutes since midnight for `HH:MM` (24:00 = 1440).
     */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parseRange(string $text): array
    {
        // Accept "-", en/em dashes, "to", "tot", "bis" between the two times.
        $normalised = preg_replace('/\s*(?:–|—|-|\bto\b|\btot\b|\bbis\b)\s*/iu', '-', $text);
        $bits = explode('-', (string) $normalised);
        if (count($bits) !== 2) {
            throw new InvalidArgumentException(_t(self::class . '.NOT_A_RANGE', '"{text}" is not a time range like 09:00-17:30.', ['text' => $text]));
        }

        $start = self::parseTime($bits[0], $text);
        $end = self::parseTime($bits[1], $text);

        if ($start === '24:00') {
            throw new InvalidArgumentException(_t(self::class . '.END_ONLY_24', '"{text}": 24:00 can only be used as a closing time.', ['text' => $text]));
        }
        if ($start === $end) {
            throw new InvalidArgumentException(_t(
                self::class . '.SAME_TIMES',
                '"{text}": opening and closing time are the same. Use 00:00-24:00 for open all day.',
                ['text' => $text]
            ));
        }

        return [$start, $end];
    }

    private static function parseTime(string $raw, string $context): string
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{1,2})(?:[:.h](\d{2}))?$/i', $raw, $m)) {
            throw new InvalidArgumentException(self::invalidTime($context, $raw));
        }
        $hours = (int) $m[1];
        $minutes = isset($m[2]) ? (int) $m[2] : 0;
        if ($hours > 24 || $minutes > 59 || ($hours === 24 && $minutes !== 0)) {
            throw new InvalidArgumentException(self::invalidTime($context, $raw));
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private static function invalidTime(string $context, string $raw): string
    {
        return _t(self::class . '.INVALID_TIME', '"{text}" contains an invalid time "{time}".', [
            'text' => $context,
            'time' => $raw,
        ]);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $ranges sorted by start
     */
    private static function assertNoOverlap(array $ranges): void
    {
        $previousEnd = null;
        foreach ($ranges as $range) {
            $start = self::minutes($range[0]);
            $end = self::isOvernight($range) ? self::minutes($range[1]) + 1440 : self::minutes($range[1]);
            if ($previousEnd !== null && $start < $previousEnd) {
                throw new InvalidArgumentException(_t(
                    self::class . '.OVERLAP',
                    'The range {range} overlaps another range.',
                    ['range' => $range[0] . '-' . $range[1]]
                ));
            }
            $previousEnd = $end;
        }
    }
}
