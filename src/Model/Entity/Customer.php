<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Customer account and contact details.
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
