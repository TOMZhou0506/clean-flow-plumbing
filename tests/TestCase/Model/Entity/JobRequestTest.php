<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\JobRequest;
use Cake\TestSuite\TestCase;

/** The job reference is shown to customers and the office, so its format must not drift. */
class JobRequestTest extends TestCase
{
    public function testReferenceIsPrefixedAndZeroPadded(): void
    {
        $this->assertSame('REQ-000002', (new JobRequest(['id' => 2]))->reference());
        $this->assertSame('REQ-000123', (new JobRequest(['id' => 123]))->reference());
    }

    public function testReferenceKeepsAllDigitsOfLargeIds(): void
    {
        $this->assertSame('REQ-1234567', (new JobRequest(['id' => 1234567]))->reference());
    }
}
