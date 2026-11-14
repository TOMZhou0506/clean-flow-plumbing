<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A short-lived form submission counter.
 *
 * @property string $rate_key
 * @property string $scope
 * @property int $attempts
 * @property \Cake\I18n\DateTime $expires_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
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
