<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/** Fleet records used by the administrator workflow tests. */
class VehiclesFixture extends TestFixture
{
    public array $records = [
        [
            'id' => 1,
            'registration_number' => 'ABC 123',
            'label' => 'Service van 1',
            'notes' => 'Standard service vehicle.',
            'active' => true,
            'created' => '2026-10-01 00:00:00',
            'modified' => '2026-10-01 00:00:00',
        ],
    ];
}
