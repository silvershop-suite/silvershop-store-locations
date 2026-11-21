<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Hours;

use DateTimeImmutable;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverStripe\Model\ModelData;

/**
 * Whether a location is open at a moment, and the next change ("Open · closes at 18:00", "Closed · opens tomorrow at
 * 09:00"). All times are in the location's timezone.
 */
class OpeningStatus extends ModelData
{
    public function __construct(
        private readonly DateTimeImmutable $at,
        private readonly bool $open,
        private readonly ?DateTimeImmutable $closesAt = null,
        private readonly ?DateTimeImmutable $opensAt = null,
        private readonly bool $closingSoon = false,
        private readonly bool $openingSoon = false,
        private readonly string $locationStatus = StoreLocation::STATUS_OPEN,
        private readonly string $reason = '',
    ) {
        parent::__construct();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function getClosesAt(): ?DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function getOpensAt(): ?DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function isClosingSoon(): bool
    {
        return $this->closingSoon;
    }

    public function isOpeningSoon(): bool
    {
        return $this->openingSoon;
    }

    public function getLocationStatus(): string
    {
        return $this->locationStatus;
    }

    // ---- template API --------------------------------------------------------------------------------------------


    public function Reason(): string
    {
        return $this->reason;
    }

    public function CssClass(): string
    {
        return match (true) {
            $this->locationStatus === StoreLocation::STATUS_TEMPORARILY_CLOSED => 'is-temporarily-closed',
            $this->locationStatus === StoreLocation::STATUS_COMING_SOON => 'is-coming-soon',
            $this->locationStatus === StoreLocation::STATUS_PERMANENTLY_CLOSED => 'is-permanently-closed',
            $this->open && $this->closingSoon => 'is-open is-closing-soon',
            $this->open => 'is-open',
            $this->openingSoon => 'is-closed is-opening-soon',
            default => 'is-closed',
        };
    }

    public function Text(): string
    {
        $c = self::class;

        switch ($this->locationStatus) {
            case StoreLocation::STATUS_PERMANENTLY_CLOSED:
                return _t($c . '.PERMANENTLY_CLOSED', 'Permanently closed');
            case StoreLocation::STATUS_COMING_SOON:
                return $this->opensAt
                    ? _t($c . '.OPENING_ON', 'Opening {date}', ['date' => $this->dateText($this->opensAt)])
                    : _t($c . '.OPENING_SOON', 'Opening soon');
            case StoreLocation::STATUS_TEMPORARILY_CLOSED:
                return $this->opensAt
                    ? _t($c . '.TEMP_CLOSED_UNTIL', 'Temporarily closed · reopens {date}', ['date' => $this->dateText($this->opensAt)])
                    : _t($c . '.TEMP_CLOSED', 'Temporarily closed');
        }

        if ($this->open) {
            if (!$this->closesAt) {
                return _t($c . '.OPEN_ALWAYS', 'Open 24 hours');
            }
            [$sameDay, $time] = $this->closingParts($this->closesAt);
            if ($this->closingSoon) {
                return _t($c . '.CLOSES_SOON', 'Closes soon · {time}', ['time' => $time]);
            }

            return $sameDay
                ? _t($c . '.OPEN_UNTIL', 'Open · closes at {time}', ['time' => $time])
                : _t($c . '.OPEN_UNTIL_DAY', 'Open · closes {day} at {time}', [
                    'day' => DayHours::formatDate($this->closesAt, 'EEEE'),
                    'time' => $time,
                ]);
        }

        if (!$this->opensAt) {
            return _t($c . '.CLOSED', 'Closed');
        }

        $time = DayHours::formatTime($this->opensAt->format('H:i'));
        $days = $this->dayDiff($this->at, $this->opensAt);
        if ($days === 0) {
            return $this->openingSoon
                ? _t($c . '.OPENS_SOON', 'Opens soon · {time}', ['time' => $time])
                : _t($c . '.OPENS_TODAY', 'Closed · opens at {time}', ['time' => $time]);
        }
        if ($days === 1) {
            return _t($c . '.OPENS_TOMORROW', 'Closed · opens tomorrow at {time}', ['time' => $time]);
        }
        if ($days < 7) {
            return _t($c . '.OPENS_WEEKDAY', 'Closed · opens {day} at {time}', [
                'day' => DayHours::formatDate($this->opensAt, 'EEEE'),
                'time' => $time,
            ]);
        }

        return _t($c . '.OPENS_DATE', 'Closed · opens {date}', ['date' => $this->dateText($this->opensAt)]);
    }

    public function forTemplate(): string
    {
        return htmlspecialchars($this->Text(), ENT_QUOTES);
    }

    /**
     * A close at 00:00 the next day reads as "24:00" today.
     *
     * @return array{0: bool, 1: string}
     */
    private function closingParts(DateTimeImmutable $closes): array
    {
        $days = $this->dayDiff($this->at, $closes);
        if ($days === 1 && $closes->format('H:i') === '00:00') {
            return [true, DayHours::formatTime('24:00')];
        }

        return [$days === 0, DayHours::formatTime($closes->format('H:i'))];
    }

    private function dayDiff(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $a = new DateTimeImmutable($from->format('Y-m-d'));
        $b = new DateTimeImmutable($to->setTimezone($from->getTimezone())->format('Y-m-d'));
        return (int) $a->diff($b)->format('%r%a');
    }

    private function dateText(DateTimeImmutable $date): string
    {
        return DayHours::formatDate($date, 'd MMMM');
    }
}
