<?php
declare(strict_types=1);

namespace App\Test\TestCase\Validation;

use App\Validation\BusinessVisitTime;
use Cake\TestSuite\TestCase;

/** Customer preferences and dispatch visits share the same local business hours. */
class BusinessVisitTimeTest extends TestCase
{
    public function testPreferredStartSlotsRunFromEightToFourInHalfHourSteps(): void
    {
        $slots = BusinessVisitTime::preferredStartSlots();

        $this->assertCount(17, $slots);
        $this->assertSame('08:00', array_key_first($slots));
        $this->assertSame('16:00', array_key_last($slots));
        $this->assertArrayNotHasKey('16:30', $slots);
    }

    public function testCustomerPreferredTimeMustBeFutureWeekdayWithinHours(): void
    {
        $this->assertTrue(BusinessVisitTime::preferredIsValid('2030-11-20T08:00'));
        $this->assertTrue(BusinessVisitTime::preferredIsValid('2030-11-20T16:00'));
        $this->assertFalse(BusinessVisitTime::preferredIsValid('2030-11-20T07:59'));
        $this->assertFalse(BusinessVisitTime::preferredIsValid('2030-11-20T16:01'));
        $this->assertFalse(BusinessVisitTime::preferredIsValid('2030-11-23T09:00'));
        $this->assertFalse(BusinessVisitTime::preferredIsValid('2020-11-20T09:00'));
    }

    public function testDurationEndsSameWorkingDayAndIsAtMostEightHours(): void
    {
        $start = BusinessVisitTime::parseForm('2030-11-20T09:00');
        $this->assertSame('17:00', BusinessVisitTime::endForDuration($start, '8')?->format('H:i'));
        $this->assertNull(BusinessVisitTime::endForDuration($start, '9'));
        $this->assertNull(BusinessVisitTime::endForDuration($start, '0'));
        $this->assertNull(BusinessVisitTime::endForDuration($start, '1.5'));
        $this->assertNull(BusinessVisitTime::endForDuration(BusinessVisitTime::parseForm('2030-11-20T16:00'), '2'));
    }
}
