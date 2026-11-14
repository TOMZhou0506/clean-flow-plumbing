<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Customer account and contact details.
 *
 * @property int $cust_id
 * @property string $cust_email
 * @property string $cust_fname
 * @property string $cust_lname
 * @property string|null $cust_phone
 * @property string|null $cust_address
 * @property string|null $cust_type
 * @property string $cust_password_hash
 * @property \App\Model\Entity\JobRequest[] $job_requests
 */
class Customer extends Entity
{
    protected array $_accessible = [
        'cust_email' => true,
        'cust_fname' => true,
        'cust_lname' => true,
        'cust_phone' => true,
        'cust_address' => true,
        'cust_type' => false,
        'cust_password_hash' => false,
        'password' => true,
        'confirm_password' => true,
    ];

    protected array $_hidden = [
        'cust_password_hash',
        'password',
        'confirm_password',
    ];
}
