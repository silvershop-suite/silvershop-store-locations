<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverShop\StoreLocations\Hours\DateRules;
use SilverShop\StoreLocations\Hours\TimeRanges;
use SilverStripe\Dev\SapphireTest;

class TimeRangesTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testParsesForgivingInputIntoSortedCanonicalRanges(): void
    {
        $this->assertSame([['09:00', '12:00'], ['13:00', '17:30']], TimeRanges::parse('13:00-17.30, 9-12'));
        $this->assertSame([['10:00', '18:00']], TimeRanges::parse('10:00 – 18:00'));
        $this->assertSame([['10:00', '18:00']], TimeRanges::parse('10 tot 18'));
        $this->assertSame([['08:30', '12:00'], ['13:00', '17:00']], TimeRanges::parse("8.30-12\n13-17"));
    }

    public function testClosedWordsAndEmptyMeanClosed(): void
    {
        $this->assertSame([], TimeRanges::parse(''));
        $this->assertSame([], TimeRanges::parse('Gesloten'));
        $this->assertSame([], TimeRanges::parse('closed'));
        $this->assertSame([], TimeRanges::parse(null));
    }

    public function testAllDayAndOvernight(): void
    {
        $this->assertSame([['00:00', '24:00']], TimeRanges::parse('0-24'));
        $this->assertFalse(TimeRanges::isOvernight(['00:00', '24:00']));
        $this->assertTrue(TimeRanges::isOvernight(['22:00', '02:00']));
        $this->assertSame([['22:00', '02:00']], TimeRanges::parse('22:00-02:00'));
    }

    #[DataProvider('invalidInput')]
    public function testRejectsInvalidInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeRanges::parse($input);
    }

    public static function invalidInput(): array
    {
        return [
            'overlap' => ['09:00-13:00, 12:00-17:00'],
            'overnight overlap' => ['22:00-02:00, 23:00-23:30'],
            'same start and end' => ['10:00-10:00'],
            'ambiguous midnight' => ['00:00-00:00'],
            '24:00 as start' => ['24:00-02:00'],
            'hour out of range' => ['25:00-26:00'],
            'minutes out of range' => ['10:75-12:00'],
            'not a range' => ['open'],
        ];
    }

    public function testTryParseReturnsNullOnError(): void
    {
        $this->assertNull(TimeRanges::tryParse('nonsense'));
        $this->assertSame('09:00-12:00,13:00-17:00', TimeRanges::toString(TimeRanges::parse('13-17,9-12')));
    }

    public function testDateRulesMatchOneOffYearlyAndWrappingRanges(): void
    {
        $this->assertTrue(DateRules::matches('2026-12-25', '2026-12-24', '2026-12-26', false));
        $this->assertFalse(DateRules::matches('2027-12-25', '2026-12-24', '2026-12-26', false));
        $this->assertTrue(DateRules::matches('2030-12-25', '2026-12-24', '2026-12-26', true));
        $this->assertTrue(DateRules::matches('2027-01-02', '2026-12-27', '2027-01-03', true), 'wraps the year end');
        $this->assertFalse(DateRules::matches('2027-01-05', '2026-12-27', '2027-01-03', true));
        $this->assertTrue(DateRules::matches('2026-05-01', '2026-05-01', '', false), 'empty end = single day');
        $this->assertSame(3, DateRules::span('2026-12-24', '2026-12-26', false));
        $this->assertSame(8, DateRules::span('2026-12-27', '2027-01-03', true));
    }
}
