<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Hours;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Spatie\OpeningHours\Exceptions\MaximumLimitExceeded;
use Spatie\OpeningHours\Exceptions\SearchLimitReached;
use Spatie\OpeningHours\OpeningHours;

/**
 * The public opening-hours API of the module. Other modules (click-and-collect, delivery-scheduling, templates,
 * the API) ask this service, never the models directly.
 *
 * Which hours apply on a date is decided by {@see HoursResolver}; spatie/opening-hours does the time arithmetic on
 * top of that (ranges past midnight, next open/close, timezone conversion), fed through a single date filter.
 *
 * Every method accepts a moment in any timezone and works in the location's own timezone. "Now" comes from
 * {@see DBDatetime::now()}, so tests can pin it with `DBDatetime::set_mock_now()`.
 */
class OpeningCalendar
{
    use Injectable;
    use Configurable;

    /** Minutes before closing that count as "closes soon". */
    private static int $closing_soon_minutes = 30;

    /** Minutes before opening that count as "opens soon". */
    private static int $opening_soon_minutes = 30;

    /** How far ahead (days) to search for the next opening or closing. */
    private static int $search_days = 400;

    /** Days of resolved hours embedded for the client-side status script. */
    private static int $client_days = 14;

    /** @var array<string, HoursResolver> */
    private array $resolvers = [];

    /** @var array<string, OpeningHours> */
    private array $engines = [];

    /** @var OpeningException[]|null */
    private ?array $globalExceptions = null;

    /**
     * Forget memoised data (called after any location, season or exception is written).
     */
    public function reset(): void
    {
        $this->resolvers = [];
        $this->engines = [];
        $this->globalExceptions = null;
    }

    public function resolver(StoreLocation $location): HoursResolver
    {
        $key = $this->key($location);
        if ($key === null) {
            return new HoursResolver($location, $this->globalExceptions());
        }

        return $this->resolvers[$key] ??= new HoursResolver($location, $this->globalExceptions());
    }

    /**
     * The spatie engine for a location, driven entirely by the resolver.
     */
    public function engine(StoreLocation $location): OpeningHours
    {
        $build = function () use ($location): OpeningHours {
            $resolver = $this->resolver($location);
            $engine = OpeningHours::create([
                'overflow' => true,
                'filters' => [
                    fn (DateTimeInterface $date) => [
                        'hours' => TimeRanges::toSpatie($resolver->forDate($date)->getRanges()),
                        'data' => $resolver->forDate($date)->getReason(),
                    ],
                ],
            ], $resolver->getTimezone());
            $engine->setDayLimit((int) self::config()->get('search_days'));

            return $engine;
        };

        $key = $this->key($location);
        return $key === null ? $build() : ($this->engines[$key] ??= $build());
    }

    /**
     * The current moment in the location's timezone.
     */
    public function now(StoreLocation $location): DateTimeImmutable
    {
        $now = new DateTimeImmutable(DBDatetime::now()->getValue(), new DateTimeZone(date_default_timezone_get()));
        return $now->setTimezone($location->getTimezoneObject());
    }

    public function isOpenAt(StoreLocation $location, ?DateTimeInterface $at = null): bool
    {
        return $this->engine($location)->isOpenAt($this->local($location, $at));
    }

    public function statusAt(StoreLocation $location, ?DateTimeInterface $at = null): OpeningStatus
    {
        $at = $this->local($location, $at);
        $status = $location->effectiveStatusOn($at);
        $reason = $this->day($location, $at)->getReason();

        if ($status !== StoreLocation::STATUS_OPEN) {
            $opensAt = $status === StoreLocation::STATUS_PERMANENTLY_CLOSED ? null : $this->nextOpen($location, $at);
            return OpeningStatus::create($at, false, null, $opensAt, false, false, $status, $reason);
        }

        if ($this->engine($location)->isOpenAt($at)) {
            $closesAt = $this->nextClose($location, $at);
            $soon = $closesAt !== null
                && ($closesAt->getTimestamp() - $at->getTimestamp()) <= 60 * (int) self::config()->get('closing_soon_minutes');
            return OpeningStatus::create($at, true, $closesAt, null, $soon, false, $status, $reason);
        }

        $opensAt = $this->nextOpen($location, $at);
        $soon = $opensAt !== null
            && ($opensAt->getTimestamp() - $at->getTimestamp()) <= 60 * (int) self::config()->get('opening_soon_minutes');

        return OpeningStatus::create($at, false, null, $opensAt, false, $soon, $status, $reason);
    }

    public function nextOpen(StoreLocation $location, ?DateTimeInterface $after = null): ?DateTimeImmutable
    {
        if (!$this->resolver($location)->hasAnyHours()) {
            return null;
        }
        try {
            $next = $this->engine($location)->nextOpen($this->local($location, $after));
        } catch (MaximumLimitExceeded | SearchLimitReached) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($next)->setTimezone($location->getTimezoneObject());
    }

    public function nextClose(StoreLocation $location, ?DateTimeInterface $after = null): ?DateTimeImmutable
    {
        try {
            $next = $this->engine($location)->nextClose($this->local($location, $after));
        } catch (MaximumLimitExceeded | SearchLimitReached) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($next)->setTimezone($location->getTimezoneObject());
    }

    /**
     * Resolved hours for one calendar date.
     */
    public function day(StoreLocation $location, DateTimeInterface $date): DayHours
    {
        return $this->resolver($location)->forDate($this->local($location, $date), $this->now($location));
    }

    /**
     * Resolved hours for consecutive days, starting today (or $from).
     *
     * @return DayHours[]
     */
    public function days(StoreLocation $location, ?DateTimeInterface $from = null, int $count = 7): array
    {
        $date = $this->local($location, $from)->setTime(0, 0);
        $today = $this->now($location);
        $resolver = $this->resolver($location);
        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $days[] = $resolver->forDate($date, $today);
            $date = $date->modify('+1 day');
        }

        return $days;
    }

    public function isOpenOn(StoreLocation $location, DateTimeInterface $date): bool
    {
        return !$this->day($location, $date)->isClosed();
    }

    /**
     * Dates (Y-m-d, ascending, unique) on which the location has no opening hours at all, inclusive range.
     *
     * @return string[]
     */
    public function closedDates(StoreLocation $location, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $date = $this->local($location, $from)->setTime(0, 0);
        $end = $this->local($location, $to)->setTime(0, 0);
        $resolver = $this->resolver($location);
        $closed = [];
        while ($date <= $end) {
            if ($resolver->forDate($date)->isClosed()) {
                $closed[] = $date->format('Y-m-d');
            }
            $date = $date->modify('+1 day');
        }

        return $closed;
    }

    /**
     * The date that is $days open days after $date (the start date itself does not count). With $days = 0 it is
     * $date when that is an open day, else the next open day. Null when none is found within the search window.
     */
    public function workingDaysAfter(StoreLocation $location, DateTimeInterface $date, int $days): ?DateTimeImmutable
    {
        $current = $this->local($location, $date)->setTime(0, 0);
        $resolver = $this->resolver($location);
        $limit = (int) self::config()->get('search_days');

        if ($days <= 0) {
            for ($i = 0; $i <= $limit; $i++) {
                if (!$resolver->forDate($current)->isClosed()) {
                    return $current;
                }
                $current = $current->modify('+1 day');
            }
            return null;
        }

        $counted = 0;
        for ($i = 0; $i < $limit; $i++) {
            $current = $current->modify('+1 day');
            if (!$resolver->forDate($current)->isClosed()) {
                $counted++;
                if ($counted === $days) {
                    return $current;
                }
            }
        }

        return null;
    }

    /**
     * The weekly pattern in effect on a date, grouped into lines of consecutive days with identical hours.
     *
     * @return array<int, array{Days: string, Hours: string, Closed: bool, Season: string}>
     */
    public function summary(StoreLocation $location, ?DateTimeInterface $date = null): array
    {
        $pattern = $this->resolver($location)->patternFor($this->local($location, $date));
        $week = $pattern['week'];

        $lines = [];
        $groupStart = 1;
        for ($day = 1; $day <= 7; $day++) {
            $current = $week[$day] ?? [];
            $next = $day < 7 ? ($week[$day + 1] ?? []) : null;
            if ($next !== null && $next === $current) {
                continue;
            }
            $lines[] = [
                'Days' => self::dayRangeLabel($groupStart, $day),
                'Hours' => $current === [] ? _t(DayHours::class . '.CLOSED', 'Closed') : DayHours::formatRanges($current),
                'Closed' => $current === [],
                'Season' => $pattern['title'],
            ];
            $groupStart = $day + 1;
        }

        return $lines;
    }

    /**
     * Deviating days (exceptions and status closures) in the coming period; consecutive days with the same hours
     * and reason are merged into one notice.
     *
     * @return array<int, array{From: string, To: string, Period: string, Hours: string, Closed: bool, Reason: string}>
     */
    public function upcomingExceptions(StoreLocation $location, ?DateTimeInterface $from = null, int $days = 60): array
    {
        $notices = [];
        $current = null;
        foreach ($this->days($location, $from, $days) as $day) {
            if (!$day->isException()) {
                $current = null;
                continue;
            }
            $signature = json_encode([$day->getRanges(), $day->getReason()]);
            if ($current !== null && $notices[$current]['signature'] === $signature) {
                $notices[$current]['to'] = $day->getDateObject();
                continue;
            }
            $notices[] = ['from' => $day->getDateObject(), 'to' => $day->getDateObject(), 'day' => $day, 'signature' => $signature];
            $current = count($notices) - 1;
        }

        return array_map(function (array $notice) {
            /** @var DayHours $day */
            $day = $notice['day'];
            $from = $notice['from'];
            $to = $notice['to'];
            $period = DayHours::formatDate($from, 'EEEE d MMMM');
            if ($to->format('Y-m-d') !== $from->format('Y-m-d')) {
                $period = _t(self::class . '.PERIOD', '{from} to {to}', [
                    'from' => DayHours::formatDate($from, 'd MMMM'),
                    'to' => DayHours::formatDate($to, 'd MMMM'),
                ]);
            }

            return [
                'From' => $from->format('Y-m-d'),
                'To' => $to->format('Y-m-d'),
                'Period' => $period,
                'Hours' => $day->Text(),
                'Closed' => $day->isClosed(),
                'Reason' => $day->getReason(),
            ];
        }, $notices);
    }

    /**
     * Data for the client-side status script: the next days' resolved ranges plus pre-translated labels.
     *
     * @return array<string, mixed>
     */
    public function clientData(StoreLocation $location): array
    {
        $now = $this->now($location);
        $c = OpeningStatus::class;
        $weekdays = [];
        for ($i = 1; $i <= 7; $i++) {
            $weekdays[$i] = DayHours::formatDate(new DateTimeImmutable('2024-01-0' . $i), 'EEEE');
        }

        return [
            'tz' => $location->getTimezoneObject()->getName(),
            'status' => $location->effectiveStatusOn($now),
            'soon' => [(int) self::config()->get('closing_soon_minutes'), (int) self::config()->get('opening_soon_minutes')],
            'h12' => str_contains(DayHours::formatTime('13:00'), 'PM') || str_contains(DayHours::formatTime('13:00'), 'pm'),
            'days' => array_map(fn (DayHours $d) => $d->toClientArray(), $this->days($location, $now, (int) self::config()->get('client_days'))),
            'weekdays' => $weekdays,
            'labels' => [
                'open' => _t($c . '.OPEN_UNTIL', 'Open · closes at {time}'),
                'openDay' => _t($c . '.OPEN_UNTIL_DAY', 'Open · closes {day} at {time}'),
                'closesSoon' => _t($c . '.CLOSES_SOON', 'Closes soon · {time}'),
                'opensSoon' => _t($c . '.OPENS_SOON', 'Opens soon · {time}'),
                'opensToday' => _t($c . '.OPENS_TODAY', 'Closed · opens at {time}'),
                'opensTomorrow' => _t($c . '.OPENS_TOMORROW', 'Closed · opens tomorrow at {time}'),
                'opensWeekday' => _t($c . '.OPENS_WEEKDAY', 'Closed · opens {day} at {time}'),
                'closed' => _t($c . '.CLOSED', 'Closed'),
                'always' => _t($c . '.OPEN_ALWAYS', 'Open 24 hours'),
            ],
        ];
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    private function local(StoreLocation $location, ?DateTimeInterface $at): DateTimeImmutable
    {
        return $at === null
            ? $this->now($location)
            : DateTimeImmutable::createFromInterface($at)->setTimezone($location->getTimezoneObject());
    }

    private function key(StoreLocation $location): ?string
    {
        return $location->isInDB() ? $location->ID . '@' . $location->LastEdited : null;
    }

    /**
     * @return OpeningException[]
     */
    private function globalExceptions(): array
    {
        return $this->globalExceptions ??= OpeningException::get()->filter('StoreLocationID', 0)->toArray();
    }

    /**
     * "Monday", "Mon to Fri" style label for ISO weekdays $from..$to.
     */
    public static function dayRangeLabel(int $from, int $to): string
    {
        $name = fn (int $day, string $pattern) => DayHours::formatDate(new DateTimeImmutable('2024-01-0' . $day), $pattern);
        if ($from === $to) {
            return $name($from, 'EEEE');
        }

        return _t(self::class . '.DAY_RANGE', '{from} to {to}', [
            'from' => $name($from, 'EEE'),
            'to' => $name($to, 'EEE'),
        ]);
    }
}
