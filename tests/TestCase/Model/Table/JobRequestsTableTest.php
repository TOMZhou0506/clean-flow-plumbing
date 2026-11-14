<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * Validation and ownership rules for job requests.
 */
class JobRequestsTableTest extends TestCase
{
    protected array $fixtures = [
        'app.Customers',
        'app.JobRequests',
    ];

    public function testValidAustralianRequestPassesValidation(): void
    {
        $table = TableRegistry::getTableLocator()->get('JobRequests');
        $request = $table->newEntity([
            'street_address' => '5 Example Street',
            'suburb' => 'Richmond',
            'state' => 'VIC',
            'postcode' => '3121',
            'job_description' => 'A tap is leaking under the sink.',
            'preferred_datetime' => '2030-10-10 09:30:00',
            'contact_phone' => '0412 345 678',
            'access_instructions' => '',
        ]);

        $this->assertSame([], $request->getErrors());
    }

    public function testInvalidStatePostcodeAndPastTimeAreRejected(): void
    {
        $table = TableRegistry::getTableLocator()->get('JobRequests');
        $request = $table->newEntity([
            'street_address' => '5 Example Street',
            'suburb' => 'Richmond',
            'state' => 'XX',
            'postcode' => '123',
            'job_description' => 'A tap is leaking.',
            'preferred_datetime' => '2020-01-01 09:30:00',
            'contact_phone' => '123',
        ]);

        $this->assertArrayHasKey('state', $request->getErrors());
        $this->assertArrayHasKey('postcode', $request->getErrors());
        $this->assertArrayHasKey('preferred_datetime', $request->getErrors());
        $this->assertArrayHasKey('contact_phone', $request->getErrors());
    }
}
