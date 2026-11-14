<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;

/**
 * User Entity
 *
 * @property string $id
 * @property string $email
 * @property string $password
 * @property string $first_name
 * @property string $last_name
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Property[] $properties
 */
class User extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'email' => true,
        'password' => true,
        'first_name' => true,
        'last_name' => true,
        'created' => true,
        'modified' => true,
        'properties' => true,
    ];

    /**
     * Fields that are excluded from JSON versions of the entity.
     *
     * @var array<string>
     */
    protected array $_hidden = [
        'password',
    ];

    /**
     * Hashes the password whenever it is set.
     *
     * This is a mutator, so assigning $user->password = 'plaintext' anywhere
     * stores the bcrypt hash instead. I put it on the entity rather than in a
     * controller so there is no code path that writes a plaintext password by
     * forgetting to hash first.
     *
     * Hashes are one-way, so adding this did not convert the plaintext rows
     * that already existed. Those accounts need their passwords set again
     * before they can sign in.
     *
     * @param string $password The plaintext password.
     * @return string|null The hash, or null when the input is empty.
     */
    protected function _setPassword(string $password): ?string
    {
        if (strlen($password) === 0) {
            return null;
        }

        return (new DefaultPasswordHasher())->hash($password);
    }
}
