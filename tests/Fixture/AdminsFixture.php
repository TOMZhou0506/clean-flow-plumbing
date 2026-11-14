<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * AdminsFixture
 *
 * Two accounts with different roles, so the "a non-admin is denied" checks in
 * US 2.4 and US 3.1 have something real to sign in as. With a single role
 * those tests would pass trivially and prove nothing.
 */
class AdminsFixture extends TestFixture
{
    /**
     * The password both accounts use. Tests post this and expect the login to
     * succeed against the stored hash.
     *
     * @var string
     */
    public const PASSWORD = 'CorrectHorse#2026';

    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        // I hash at runtime rather than pasting a fixed hash in. A literal
        // hash would silently stop matching if the cost factor or default
        // algorithm changes, and the failure would look like a broken login
        // rather than a stale fixture.
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);

        $this->records = [
            [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'admin_email' => 'hannah@example.com',
                'admin_password' => $hash,
                'role' => 'admin',
                'active' => true,
            ],
            [
                'admin_id' => 2,
                'admin_username' => 'staffuser',
                'admin_email' => 'staffuser@example.com',
                'admin_password' => $hash,
                'role' => 'staff',
                'active' => true,
            ],
            [
                'admin_id' => 3,
                'admin_username' => 'manageruser',
                'admin_email' => 'manageruser@example.com',
                'admin_password' => $hash,
                'role' => 'manager',
                'active' => true,
            ],
        ];
        parent::init();
    }
}
