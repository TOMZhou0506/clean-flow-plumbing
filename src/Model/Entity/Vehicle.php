<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/** A vehicle in the business fleet. */
/**
 * @property int $id
 * @property string $registration_number
 * @property string $label
 * @property string|null $notes
 * @property bool $active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
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
