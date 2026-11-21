<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Tests;

use DateTimeImmutable;
use DateTimeZone;
use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverShop\StoreLocations\Hours\DayHours;
use SilverShop\StoreLocations\Hours\OpeningCalendar;
use SilverShop\StoreLocations\Model\OpeningException;
use SilverShop\StoreLocations\Model\OpeningSchedule;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The opening-hours engine: precedence (status > location exception > global exception > season > regular),
 * ranges past midnight, DST, timezones, leap day, closed dates / working days, summaries and status texts.
 *
 * Reference week: Monday 23 November 2026, location in Europe/Amsterdam (CET, UTC+1).
 */
class OpeningCalendarTest extends SapphireTest
{
    protected $usesDatabase = true;

    private string $previousTimezone = '';

    private string $previousLocale = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->previousLocale = i18n::get_locale();
        i18n::set_locale('en_GB');
        DBDatetime::set_mock_now('2026-11-23 09:00:00'); // 10:00 in Amsterdam
        OpeningCalendar::singleton()->reset();
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        date_default_timezone_set($this->previousTimezone);
        i18n::set_locale($this->previousLocale);
        parent::tearDown();
    }

    private function calendar(): OpeningCalendar
    {
        return OpeningCalendar::singleton();
    }

    /**
     * @param array<int, string>|null $week
     * @param array<string, mixed> $extra
     */
    private function location(?array $week = null, array $extra = []): StoreLocation
    {
        $location = StoreLocation::create(array_merge([
            'Title' => 'High Street',
            'Street' => 'High Street',
            'HouseNumber' => '1',
            'PostalCode' => '2611 JA',
            'City' => 'Delft',
            'CountryCode' => 'NL',
            'Timezone' => 'Europe/Amsterdam',
            'RegularHours' => WeeklyHoursField::encode($week ?? [
                1 => '10:00-18:00',
                2 => '10:00-18:00',
                3 => '10:00-18:00',
                4 => '10:00-18:00',
                5 => '10:00-21:00',
                6 => '10:00-17:00',
                7 => '',
            ]),
        ], $extra));
        $location->write();

        return $location;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function exception(?StoreLocation $location, array $data): OpeningException
    {
        $exception = OpeningException::create(array_merge(['StoreLocationID' => $location?->ID ?? 0], $data));
        $exception->write();

        return $exception;
    }

    private function at(string $local, string $tz = 'Europe/Amsterdam'): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone($tz));
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function rangesOn(StoreLocation $location, string $date): array
    {
        return $this->calendar()->day($location, $this->at($date . ' 12:00'))->getRanges();
    }

    public function testRegularWeek(): void
    {
        $location = $this->location();

        $this->assertTrue($this->calendar()->isOpenAt($location, $this->at('2026-11-23 12:00')));
        $this->assertTrue($this->calendar()->isOpenAt($location, $this->at('2026-11-23 10:00')));
        $this->assertFalse($this->calendar()->isOpenAt($location, $this->at('2026-11-23 18:00')), 'closing time is exclusive');
        $this->assertFalse($this->calendar()->isOpenAt($location, $this->at('2026-11-29 12:00')), 'closed on Sunday');
        $this->assertTrue($this->calendar()->isOpenAt($location, $this->at('2026-11-27 20:30')), 'late Friday');
    }

    public function testSeasonReplacesRegularWeekWithinItsDates(): void
    {
        $location = $this->location();
        OpeningSchedule::create([
            'StoreLocationID' => $location->ID,
            'Title' => 'December',
            'ValidFrom' => '2026-12-01',
            'ValidUntil' => '2026-12-31',
            'WeeklyHours' => WeeklyHoursField::encode([1 => '10:00-18:00', 7 => '12:00-17:00']),
        ])->write();

        $this->assertSame([['12:00', '17:00']], $this->rangesOn($location, '2026-12-06'), 'December Sunday');
        $this->assertSame([], $this->rangesOn($location, '2026-11-29'), 'November Sunday stays closed');
        $this->assertSame([], $this->rangesOn($location, '2026-12-08'), 'a season replaces the whole week');
        $this->assertSame(DayHours::SOURCE_SEASON, $this->calendar()->day($location, $this->at('2026-12-06 12:00'))->getSource());
    }

    public function testYearlySeasonWrapsTheYearEnd(): void
    {
        $location = $this->location();
        OpeningSchedule::create([
            'StoreLocationID' => $location->ID,
            'Title' => 'Winter',
            'ValidFrom' => '2026-12-15',
            'ValidUntil' => '2027-01-10',
            'RecurringYearly' => true,
            'WeeklyHours' => WeeklyHoursField::encode([7 => '11:00-16:00']),
        ])->write();

        $this->assertSame([['11:00', '16:00']], $this->rangesOn($location, '2027-01-03'));
        $this->assertSame([['11:00', '16:00']], $this->rangesOn($location, '2028-01-09'), 'repeats next year');
        $this->assertSame([], $this->rangesOn($location, '2027-01-17'), 'after the season');
    }

    public function testHighestPrioritySeasonWins(): void
    {
        $location = $this->location();
        foreach ([['Low', 1, '09:00-10:00'], ['High', 5, '09:00-11:00']] as [$title, $priority, $hours]) {
            OpeningSchedule::create([
                'StoreLocationID' => $location->ID,
                'Title' => $title,
                'ValidFrom' => '2026-12-01',
                'ValidUntil' => '2026-12-31',
                'Priority' => $priority,
                'WeeklyHours' => WeeklyHoursField::encode([1 => $hours]),
            ])->write();
        }

        $this->assertSame([['09:00', '11:00']], $this->rangesOn($location, '2026-12-07'));
    }

    public function testClosedExceptionClosesTheWholeDay(): void
    {
        $location = $this->location();
        $this->exception($location, ['DateFrom' => '2026-11-24', 'Type' => 'Closed', 'Reason' => 'Inventory']);

        $day = $this->calendar()->day($location, $this->at('2026-11-24 12:00'));
        $this->assertTrue($day->isClosed());
        $this->assertSame('Inventory', $day->getReason());
        $this->assertFalse($this->calendar()->isOpenAt($location, $this->at('2026-11-24 12:00')));
    }

    /**
     * Regression (legacy chocolaterie Q11a): a multi-day exception must apply to every day in its range.
     */
    public function testMultiDayExceptionAppliesToEveryDay(): void
    {
        $location = $this->location();
        $this->exception($location, [
            'DateFrom' => '2026-12-28',
            'DateTo' => '2026-12-30',
            'Type' => 'SpecialHours',
            'Hours' => '12:00-16:00',
        ]);

        foreach (['2026-12-28', '2026-12-29', '2026-12-30'] as $date) {
            $this->assertSame([['12:00', '16:00']], $this->rangesOn($location, $date), $date);
        }
        $this->assertSame([['10:00', '18:00']], $this->rangesOn($location, '2026-12-31'), 'day after the range is regular');
    }

    public function testYearlyExceptionRecurs(): void
    {
        $location = $this->location();
        $this->exception($location, ['DateFrom' => '2026-12-25', 'DateTo' => '2026-12-26', 'RecurringYearly' => true, 'Type' => 'Closed']);

        $this->assertSame([], $this->rangesOn($location, '2027-12-25'));
        $this->assertSame([], $this->rangesOn($location, '2028-12-26'));
        $this->assertSame([['10:00', '18:00']], $this->rangesOn($location, '2027-12-27'));
    }

    public function testLocationExceptionBeatsGlobalClosingDay(): void
    {
        $delft = $this->location();
        $riverside = $this->location(null, ['Title' => 'Riverside', 'City' => 'Riverside']);
        $this->exception(null, ['DateFrom' => '2026-12-24', 'Type' => 'Closed', 'Reason' => 'Christmas Eve']);
        $this->exception($delft, ['DateFrom' => '2026-12-24', 'Type' => 'SpecialHours', 'Hours' => '09:00-12:00']);

        $this->assertSame([['09:00', '12:00']], $this->rangesOn($delft, '2026-12-24'));
        $this->assertSame([], $this->rangesOn($riverside, '2026-12-24'));
        $this->assertSame(
            DayHours::SOURCE_GLOBAL_EXCEPTION,
            $this->calendar()->day($riverside, $this->at('2026-12-24 12:00'))->getSource()
        );
    }

    public function testOneOffExceptionBeatsYearlyOne(): void
    {
        $location = $this->location();
        $this->exception($location, ['DateFrom' => '2026-12-31', 'RecurringYearly' => true, 'Type' => 'Closed']);
        $this->exception($location, ['DateFrom' => '2026-12-31', 'Type' => 'SpecialHours', 'Hours' => '10:00-14:00']);

        $this->assertSame([['10:00', '14:00']], $this->rangesOn($location, '2026-12-31'));
        $this->assertSame([], $this->rangesOn($location, '2027-12-31'));
    }

    public function testRangePastMidnightCountsForTheNextMorning(): void
    {
        $location = $this->location([5 => '20:00-02:00']);

        $this->assertTrue($this->calendar()->isOpenAt($location, $this->at('2026-11-27 23:30')));
        $this->assertTrue($this->calendar()->isOpenAt($location, $this->at('2026-11-28 01:30')), 'Saturday 01:30 is still Friday night');
        $this->assertFalse($this->calendar()->isOpenAt($location, $this->at('2026-11-28 02:00')));

        $status = $this->calendar()->statusAt($location, $this->at('2026-11-27 23:00'));
        $this->assertTrue($status->isOpen());
        $this->assertSame('2026-11-28 02:00', $status->getClosesAt()?->format('Y-m-d H:i'));
    }

    public function testOpenAllDayHasNoClosingTime(): void
    {
        $location = $this->location(array_fill(1, 7, '00:00-24:00'));

        $status = $this->calendar()->statusAt($location, $this->at('2026-11-23 03:00'));
        $this->assertTrue($status->isOpen());
        $this->assertNull($status->getClosesAt());
        $this->assertSame('Open 24 hours', $status->Text());
    }

    public function testDaylightSavingSpring(): void
    {
        // 29 March 2026: clocks go from 02:00 CET to 03:00 CEST.
        $location = $this->location([7 => '09:00-17:00']);
        $closes = $this->calendar()->nextClose($location, $this->at('2026-03-29 10:00'));

        $this->assertSame('2026-03-29 17:00', $closes?->format('Y-m-d H:i'));
        $this->assertSame(strtotime('2026-03-29 15:00:00 UTC'), $closes?->getTimestamp(), '17:00 CEST is 15:00 UTC');
    }

    public function testDaylightSavingAutumn(): void
    {
        // 25 October 2026: clocks go from 03:00 CEST back to 02:00 CET.
        $location = $this->location([7 => '09:00-17:00']);
        $closes = $this->calendar()->nextClose($location, $this->at('2026-10-25 10:00'));

        $this->assertSame(strtotime('2026-10-25 16:00:00 UTC'), $closes?->getTimestamp(), '17:00 CET is 16:00 UTC');
    }

    public function testTimezoneOfTheLocationIsUsed(): void
    {
        $location = $this->location([1 => '09:00-17:00'], ['Timezone' => 'America/New_York']);

        $this->assertTrue($this->calendar()->isOpenAt($location, $this->at('2026-11-23 15:00', 'UTC')), '10:00 in New York');
        $this->assertFalse($this->calendar()->isOpenAt($location, $this->at('2026-11-23 13:00', 'UTC')), '08:00 in New York');
    }

    public function testLeapDayExceptionOnlyMatchesLeapYears(): void
    {
        $location = $this->location(array_fill(1, 7, '10:00-18:00'));
        $this->exception($location, ['DateFrom' => '2028-02-29', 'RecurringYearly' => true, 'Type' => 'Closed']);

        $this->assertSame([], $this->rangesOn($location, '2028-02-29'));
        $this->assertSame([['10:00', '18:00']], $this->rangesOn($location, '2027-02-28'));
        $this->assertSame([['10:00', '18:00']], $this->rangesOn($location, '2029-03-01'));
    }

    public function testTemporarilyClosedUntilADate(): void
    {
        $location = $this->location(null, [
            'Status' => StoreLocation::STATUS_TEMPORARILY_CLOSED,
            'StatusUntil' => '2026-11-25',
            'StatusMessage' => 'Renovation',
        ]);

        $this->assertSame([], $this->rangesOn($location, '2026-11-24'));
        $this->assertSame([['10:00', '18:00']], $this->rangesOn($location, '2026-11-26'));

        $status = $this->calendar()->statusAt($location);
        $this->assertFalse($status->isOpen());
        $this->assertSame(StoreLocation::STATUS_TEMPORARILY_CLOSED, $status->getLocationStatus());
        $this->assertSame('2026-11-26 10:00', $status->getOpensAt()?->format('Y-m-d H:i'));
        $this->assertStringContainsString('reopens', $status->Text());
    }

    public function testComingSoonIsClosedUntilItOpens(): void
    {
        $location = $this->location(null, ['Status' => StoreLocation::STATUS_COMING_SOON, 'OpensOn' => '2026-12-01']);

        $this->assertSame([], $this->rangesOn($location, '2026-11-30'));
        $this->assertSame([['10:00', '18:00']], $this->rangesOn($location, '2026-12-01'));
        $this->assertStringContainsString('Opening', $this->calendar()->statusAt($location)->Text());
    }

    public function testPermanentlyClosedNeverReopens(): void
    {
        $location = $this->location(null, ['Status' => StoreLocation::STATUS_PERMANENTLY_CLOSED]);

        $this->assertNull($this->calendar()->nextOpen($location));
        $this->assertSame('Permanently closed', $this->calendar()->statusAt($location)->Text());
    }

    public function testLocationWithoutHoursIsClosedWithoutErrors(): void
    {
        $location = $this->location([]);

        $this->assertNull($this->calendar()->nextOpen($location));
        $this->assertSame('Closed', $this->calendar()->statusAt($location)->Text());
    }

    /**
     * Includes the legacy chocolaterie Q11b regression: a date closed by two rules is listed once.
     */
    public function testClosedDatesAndWorkingDays(): void
    {
        $location = $this->location();
        $this->exception($location, ['DateFrom' => '2026-11-30', 'Type' => 'Closed']);
        $this->exception(null, ['DateFrom' => '2026-11-30', 'Type' => 'Closed']);

        $this->assertSame(
            ['2026-11-29', '2026-11-30'],
            $this->calendar()->closedDates($location, $this->at('2026-11-27 00:00'), $this->at('2026-12-01 00:00'))
        );

        // Friday + 3 working days: Sat 28 (1), Sun closed, Mon 30 closed, Tue 1 (2), Wed 2 (3).
        $this->assertSame('2026-12-02', $this->calendar()->workingDaysAfter($location, $this->at('2026-11-27 12:00'), 3)?->format('Y-m-d'));
        $this->assertSame('2026-12-01', $this->calendar()->workingDaysAfter($location, $this->at('2026-11-29 12:00'), 0)?->format('Y-m-d'));
        $this->assertTrue($this->calendar()->isOpenOn($location, $this->at('2026-11-28 08:00')));
        $this->assertFalse($this->calendar()->isOpenOn($location, $this->at('2026-11-30 08:00')));
    }

    /**
     * Includes the legacy chocolaterie Q11c regression: the summary reflects the schedule, and an exception in the
     * current week shows in the week table instead of breaking the summary.
     */
    public function testSummaryGroupsConsecutiveDaysAndWeekShowsExceptions(): void
    {
        $location = $this->location();
        $this->exception($location, ['DateFrom' => '2026-11-25', 'Type' => 'Closed', 'Reason' => 'Staff day']);

        $summary = $this->calendar()->summary($location);
        $this->assertCount(4, $summary);
        $this->assertSame('Mon to Thu', $summary[0]['Days']);
        $this->assertSame('10:00 – 18:00', $summary[0]['Hours']);
        $this->assertSame('Sunday', $summary[3]['Days']);
        $this->assertTrue($summary[3]['Closed']);

        $week = $this->calendar()->days($location, null, 7);
        $this->assertTrue($week[0]->isToday());
        $this->assertTrue($week[2]->isClosed(), 'Wednesday has the exception');
        $this->assertSame('Staff day', $week[2]->getReason());
    }

    public function testStatusTexts(): void
    {
        $location = $this->location();

        $soon = $this->calendar()->statusAt($location, $this->at('2026-11-23 17:45'));
        $this->assertTrue($soon->isClosingSoon());
        $this->assertSame('Closes soon · 18:00', $soon->Text());

        $open = $this->calendar()->statusAt($location, $this->at('2026-11-23 12:00'));
        $this->assertSame('Open · closes at 18:00', $open->Text());

        $evening = $this->calendar()->statusAt($location, $this->at('2026-11-23 19:00'));
        $this->assertSame('Closed · opens tomorrow at 10:00', $evening->Text());

        $morning = $this->calendar()->statusAt($location, $this->at('2026-11-23 09:45'));
        $this->assertTrue($morning->isOpeningSoon());

        $sunday = $this->calendar()->statusAt($location, $this->at('2026-11-29 12:00'));
        $this->assertSame('Closed · opens tomorrow at 10:00', $sunday->Text());

        $saturdayNight = $this->calendar()->statusAt($location, $this->at('2026-11-28 18:00'));
        $this->assertSame('Closed · opens Monday at 10:00', $saturdayNight->Text());
    }

    public function testUpcomingExceptionsMergeConsecutiveDays(): void
    {
        $location = $this->location();
        $this->exception($location, ['DateFrom' => '2026-12-25', 'DateTo' => '2026-12-26', 'Type' => 'Closed', 'Reason' => 'Christmas']);
        $this->exception($location, ['DateFrom' => '2026-12-31', 'Type' => 'SpecialHours', 'Hours' => '10:00-16:00', 'Reason' => 'New Year']);

        $notices = $this->calendar()->upcomingExceptions($location, null, 60);
        $this->assertCount(2, $notices);
        $this->assertSame(['2026-12-25', '2026-12-26'], [$notices[0]['From'], $notices[0]['To']]);
        $this->assertTrue($notices[0]['Closed']);
        $this->assertSame('New Year', $notices[1]['Reason']);
        $this->assertSame('10:00 – 16:00', $notices[1]['Hours']);
    }

    public function testClientDataForTheStatusScript(): void
    {
        $data = $this->calendar()->clientData($this->location());

        $this->assertSame('Europe/Amsterdam', $data['tz']);
        $this->assertCount(14, $data['days']);
        $this->assertSame(['d' => '2026-11-23', 'r' => [['10:00', '18:00']], 'x' => ''], $data['days'][0]);
        $this->assertFalse($data['h12']);
        $this->assertArrayHasKey('opensTomorrow', $data['labels']);
    }
}
