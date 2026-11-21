<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Tests;

use SilverShop\StoreLocations\Forms\WeeklyHoursField;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;

class WeeklyHoursFieldTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('en_GB');
    }

    public function testSubmittedTextIsStoredCanonically(): void
    {
        $field = WeeklyHoursField::create('RegularHours');
        $field->setSubmittedValue(['1' => '9-12, 13-17', '2' => ' ', '7' => 'gesloten']);

        $this->assertTrue($field->validate()->isValid());
        $this->assertSame(
            ['1' => '09:00-12:00,13:00-17:00', '2' => '', '3' => '', '4' => '', '5' => '', '6' => '', '7' => ''],
            json_decode((string) $field->dataValue(), true)
        );
    }

    public function testInvalidDayFailsValidationAndKeepsTheTypedText(): void
    {
        $field = WeeklyHoursField::create('RegularHours');
        $field->setSubmittedValue(['3' => '25:00-26:00']);

        $result = $field->validate();
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Wednesday', $result->getMessages()[0]['message']);
        $this->assertSame('25:00-26:00', $field->Days()->find('Day', 3)?->Value);
    }

    public function testRendersSevenInputs(): void
    {
        $field = WeeklyHoursField::create('RegularHours', 'Hours', WeeklyHoursField::encode([5 => '10:00-21:00']));
        $html = (string) $field->Field();

        $this->assertSame(7, substr_count($html, 'name="RegularHours['));
        $this->assertStringContainsString('value="10:00-21:00"', $html);
        $this->assertStringContainsString('Friday', $html);
    }

    public function testDecodeHelpers(): void
    {
        $json = WeeklyHoursField::encode([1 => '9-17']);
        $this->assertSame([['09:00', '17:00']], WeeklyHoursField::decodeToRanges($json)[1]);
        $this->assertSame([], WeeklyHoursField::decodeToRanges('')[1]);
        $this->assertSame([2], WeeklyHoursField::invalidDays((string) json_encode(['2' => 'nonsense'])));
    }
}
