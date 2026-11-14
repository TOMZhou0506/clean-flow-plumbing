<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/** A dated interval when a plumber cannot work. */
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
