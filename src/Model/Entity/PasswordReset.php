<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A reset code issued to an office account.
 *
 * Nothing is mass assignable: only PasswordResetService sets these fields.
 *
 * @property int $id
 * @property int $admin_id
 * @property string $code_hash
 * @property \Cake\I18n\DateTime $expires
 * @property int $attempts
 * @property \Cake\I18n\DateTime|null $used
 * @property \Cake\I18n\DateTime $created
 */
class PasswordReset extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = ['code_hash'];
}
