<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Hours;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;

/**
 * Decides which opening hours apply to one location on one date. First match wins and replaces the whole day:
 *
 * 0. the location's status (temporarily closed, coming soon, permanently closed);
 * 1. an exception for this location (one-off before yearly, then the shortest span, then the newest);
 * 2. an exception for all locations (same ordering);
 * 3. the highest-priority season covering the date (then the latest start, then the newest);
 * 4. the regular week.
 *
 * All data is loaded once; results are memoised per date.
 */
final class HoursResolver
{
    /** @var array<int, array<int, array{0: string, 1: string}>> */
    private array $regular;

    /** @var array<int, array{title: string, from: string, to: string, yearly: bool, priority: int, id: int, week: array<int, array<int, array{0: string, 1: string}>>}> */
    private array $seasons = [];

    /** @var array<int, array{from: string, to: string, yearly: bool, ranges: array<int, array{0: string, 1: string}>, reason: string, global: bool, span: int, id: int}> */
    private array $exceptions = [];

    /** @var array<string, DayHours> */
    private array $memo = [];

    private DateTimeZone $timezone;

    /**
     * @param iterable<OpeningException> $globalExceptions exceptions for all locations
     */
    public function __construct(private readonly StoreLocation $location, iterable $globalExceptions = [])
    {
        $this->timezone = $location->getTimezoneObject();
        $this->regular = $location->regularWeek();

        if ($location->isInDB()) {
            foreach ($location->Schedules() as $schedule) {
                $this->seasons[] = [
                    'title' => (string) $schedule->Title,
                    'from' => (string) $schedule->ValidFrom,
                    'to' => (string) $schedule->ValidUntil,
                    'yearly' => (bool) $schedule->RecurringYearly,
                    'priority' => (int) $schedule->Priority,
                    'id' => (int) $schedule->ID,
                    'week' => $schedule->week(),
                ];
            }
            foreach ($location->Exceptions() as $exception) {
                $this->exceptions[] = $this->exceptionRow($exception, false);
            }
        }
        foreach ($globalExceptions as $exception) {
            $this->exceptions[] = $this->exceptionRow($exception, true);
        }

        // Most specific first: location before global, one-off before yearly, shortest span, newest.
        usort($this->exceptions, fn (array $a, array $b) => [$a['global'], $a['yearly'], $a['span'], -$a['id']]
            <=> [$b['global'], $b['yearly'], $b['span'], -$b['id']]);
        usort($this->seasons, fn (array $a, array $b) => [-$a['priority'], $b['from'], -$a['id']]
            <=> [-$b['priority'], $a['from'], -$b['id']]);
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * @return array{from: string, to: string, yearly: bool, ranges: array<int, array{0: string, 1: string}>, reason: string, global: bool, span: int, id: int}
     */
    private function exceptionRow(OpeningException $exception, bool $global): array
    {
        $from = (string) $exception->DateFrom;
        $to = (string) ($exception->DateTo ?: $exception->DateFrom);
        $yearly = (bool) $exception->RecurringYearly;

        return [
            'from' => $from,
            'to' => $to,
            'yearly' => $yearly,
            'ranges' => $exception->ranges(),
            'reason' => (string) $exception->Reason,
            'global' => $global,
            'span' => DateRules::span($from, $to, $yearly),
            'id' => (int) $exception->ID,
        ];
    }

    /**
     * The hours for a calendar date (the date part is read in the location's timezone).
     */
    public function forDate(DateTimeInterface $date, ?DateTimeImmutable $today = null): DayHours
    {
        $local = DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone)->setTime(0, 0);
        $key = $local->format('Y-m-d') . '|' . ($today?->format('Y-m-d') ?? '');
        if (!isset($this->memo[$key])) {
            $this->memo[$key] = $this->resolve($local, $today);
        }

        return $this->memo[$key];
    }

    private function resolve(DateTimeImmutable $date, ?DateTimeImmutable $today): DayHours
    {
        $day = $date->format('Y-m-d');

        $status = $this->location->effectiveStatusOn($date);
        if ($status !== StoreLocation::STATUS_OPEN) {
            $reason = (string) $this->location->StatusMessage ?: (StoreLocation::statusOptions()[$status] ?? '');
            return DayHours::create($date, [], DayHours::SOURCE_STATUS, '', $reason, $today);
        }

        foreach ($this->exceptions as $exception) {
            if (DateRules::matches($day, $exception['from'], $exception['to'], $exception['yearly'])) {
                return DayHours::create(
                    $date,
                    $exception['ranges'],
                    $exception['global'] ? DayHours::SOURCE_GLOBAL_EXCEPTION : DayHours::SOURCE_EXCEPTION,
                    '',
                    $exception['reason'],
                    $today
                );
            }
        }

        $weekday = (int) $date->format('N');
        foreach ($this->seasons as $season) {
            if (DateRules::matches($day, $season['from'], $season['to'], $season['yearly'])) {
                return DayHours::create($date, $season['week'][$weekday] ?? [], DayHours::SOURCE_SEASON, $season['title'], '', $today);
            }
        }

        return DayHours::create($date, $this->regular[$weekday] ?? [], DayHours::SOURCE_REGULAR, '', '', $today);
    }

    /**
     * The weekly pattern in effect on a date (a season or the regular week), ignoring one-off exceptions. Used for
     * the hours summary and structured data.
     *
     * @return array{title: string, week: array<int, array<int, array{0: string, 1: string}>>}
     */
    public function patternFor(DateTimeInterface $date): array
    {
        $day = DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone)->format('Y-m-d');
        foreach ($this->seasons as $season) {
            if (DateRules::matches($day, $season['from'], $season['to'], $season['yearly'])) {
                return ['title' => $season['title'], 'week' => $season['week']];
            }
        }

        return ['title' => '', 'week' => $this->regular];
    }

    /**
     * Whether the location has any opening hours at all (regular or seasonal).
     */
    public function hasAnyHours(): bool
    {
        foreach ($this->regular as $ranges) {
            if ($ranges !== []) {
                return true;
            }
        }
        foreach ($this->seasons as $season) {
            foreach ($season['week'] as $ranges) {
                if ($ranges !== []) {
                    return true;
                }
            }
        }
        foreach ($this->exceptions as $exception) {
            if ($exception['ranges'] !== []) {
                return true;
            }
        }

        return false;
    }
}
