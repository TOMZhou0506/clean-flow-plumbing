<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/** A vehicle in the business fleet. */
class Vehicle extends Entity
{
    /**
     * Only descriptive fields may be filled from a form submission.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'registration_number' => true,
        'label' => true,
        'notes' => true,
        'id' => false,
        'active' => false,
        'created' => false,
        'modified' => false,
    ];
}
