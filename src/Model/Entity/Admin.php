<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;

/**
 * Admin Entity
 *
 * @property int $admin_id
 * @property string $admin_username The name shown in lists, greetings and the audit trail. It does not sign anyone in.
 * @property string $admin_email The address the account signs in with.
 * @property string $admin_password
 * @property string|null $confirm_password
 * @property string $role
 * @property bool $active
 */
class Admin extends Entity
{
    /**
     * The account can open the enquiry dashboard and the compliance screens.
     *
     * @var string
     */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    /**
     * A signed-in account with no access to enquiry or compliance data. This
     * exists so "log in as a non-admin and confirm you are denied" is a test
     * we can actually run. Without a second role that check is untestable,
     * because every account would pass it.
     *
     * @var string
     */
    public const ROLE_STAFF = 'staff';

    /**
     * @var list<string>
     */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_STAFF];

    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * `role` is deliberately NOT accessible. If it were, a POST to
     * /admins/edit carrying role=admin would let a staff account promote
     * itself, which defeats the whole authorisation check. Role changes go
     * through AdminsTable::changeRole().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'admin_username' => true,
        'admin_email' => true,
        'admin_password' => true,
        'confirm_password' => true,

        'admin_id' => false,
        'role' => false,
        'active' => false,
    ];

    /**
     * Never send the hash to a template or a JSON response.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'admin_password',
        'confirm_password',
    ];

    /**
     * Hashes a value assigned to admin_password.
     *
     * This method used to be called _setPassword(), and it never ran once.
     * CakePHP builds the mutator name from the field, so _setPassword() fires
     * for a field called `password`, and our column is `admin_password`. The
     * result was that every account created through /admins/add stored the
     * password exactly as typed. It looked like it worked, because logging in
     * compared plaintext against plaintext.
     *
     * Renaming it to _setAdminPassword() is what activates it.
     *
     * Two consequences worth knowing:
     *
     *  - Rows written before this change still hold plaintext. Hashing is one
     *    way, so I cannot convert them by reading them. Migration
     *    20260821000002 rehashes them; see the note in that file.
     *  - Anyone whose password was stored in plaintext should change it. The
     *    old value was readable by anyone with database access, so it has to
     *    be treated as already exposed.
     *
     * @param string $password The plaintext password.
     * @return string|null The hash, or null when the input is empty.
     * @see \App\Model\Entity\Admin::$admin_password
     */
    protected function _setAdminPassword(string $password): ?string
    {
        // An empty string would otherwise hash to a real, valid hash, and the
        // account would end up with a password nobody knows but that still
        // authenticates against "". I return null so the not-empty validation
        // rule catches it instead.
        if (strlen($password) === 0) {
            return null;
        }

        return (new DefaultPasswordHasher())->hash($password);
    }

    /**
     * Whether this account may reach admin-only screens.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
