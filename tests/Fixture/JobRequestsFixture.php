<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Existing requests used to verify confirmation-page ownership.
 */
class JobRequestsFixture extends TestFixture
{
    public array $records = [
        [
            'id' => 1,
            'customer_id' => 1,
            'street_address' => '12 Clayton Road',
            'suburb' => 'Clayton',
            'state' => 'VIC',
            'postcode' => '3168',
            'job_description' => 'Leaking kitchen tap.',
            'preferred_datetime' => '2030-09-15 10:30:00',
            'contact_phone' => '0412 345 678',
            'access_instructions' => 'Please use the side gate.',
            'status' => 'submitted',
            'created' => '2026-09-08 00:00:00',
            'modified' => '2026-09-08 00:00:00',
        ],
        [
            'id' => 2,
            'customer_id' => 2,
            'street_address' => '99 Private Street',
            'suburb' => 'Melbourne',
            'state' => 'VIC',
            'postcode' => '3000',
            'job_description' => 'This belongs to another customer.',
            'preferred_datetime' => '2030-09-16 11:00:00',
            'contact_phone' => '0499 000 000',
            'access_instructions' => null,
            'status' => 'submitted',
            'created' => '2026-09-08 00:00:00',
            'modified' => '2026-09-08 00:00:00',
        ],
    ];
}
