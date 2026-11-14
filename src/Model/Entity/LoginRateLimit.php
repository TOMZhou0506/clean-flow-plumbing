<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A short-lived login attempt counter.
 */
class LoginRateLimit extends Entity
{
    /**
     * Counters are written only by LoginRateLimitsTable.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'rate_key' => false,
        'scope' => false,
        'attempts' => false,
        'expires_at' => false,
        'created' => false,
        'modified' => false,
    ];
}
