<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Customer accounts used by job request tests.
 */
class CustomersFixture extends TestFixture
{
    public function init(): void
    {
        $this->records = [
            [
                'cust_id' => 1,
                'cust_email' => 'kash@example.com',
                'cust_fname' => 'Kash',
                'cust_lname' => 'Patel',
                'cust_phone' => '0412 345 678',
                'cust_address' => null,
                'cust_type' => 'customer',
                'cust_password_hash' => password_hash('Customer#2026', PASSWORD_DEFAULT),
            ],
            [
                'cust_id' => 2,
                'cust_email' => 'other@example.com',
                'cust_fname' => 'Other',
                'cust_lname' => 'Customer',
                'cust_phone' => '0499 000 000',
                'cust_address' => null,
                'cust_type' => 'customer',
                'cust_password_hash' => password_hash('Customer#2026', PASSWORD_DEFAULT),
            ],
        ];
        parent::init();
    }
}
