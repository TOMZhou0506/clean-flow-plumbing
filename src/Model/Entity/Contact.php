<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Author: Xiaoyu Zhou
 * Student ID: 35484632
 * Date: Aug 20 2026
 * Version: 1.0
 *
 * Function:
 * Handles contact form submissions, CAPTCHA verification,
 * validation, database storage, and user feedback.
 */
/**
 * Contact Entity
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $message
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */

class Contact extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'email' => true,
        'message' => true,
        'created' => true,
        'modified' => true,
    ];
}
