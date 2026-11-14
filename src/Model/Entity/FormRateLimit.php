<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A short-lived form submission counter.
 */
class FormRateLimit extends Entity
{
    protected array $_accessible = [
        'rate_key' => false,
        'scope' => false,
        'attempts' => false,
        'expires_at' => false,
        'created' => false,
        'modified' => false,
    ];
}
