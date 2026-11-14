<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/** A dated interval when a plumber cannot work. */
/**
 * @property int $id
 * @property int $admin_id
 * @property \Cake\I18n\Date $unavailable_date
 * @property int $start_minute
 * @property int $end_minute
 * @property \Cake\I18n\DateTime|null $cancelled_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \App\Model\Entity\Admin $admin
 */
class PlumberUnavailability extends Entity
{
    /**
     * All fields are set by trusted controller code, never mass assigned.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];
}
