<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * admins
 *
 * Suhana built this table by hand in phpMyAdmin, so it never had a migration.
 * That was invisible on our own machines, because we all had the table sitting
 * in clean_flow_plumbing_db already. It only showed up in the test suite,
 * which builds its database purely from migrations: AdminsFixture,
 * AdminsControllerTest and AdminsTableTest all died with "Cannot describe
 * admins. It has 0 columns."
 *
 * I transcribed the column types straight out of her dump so this matches what
 * is already live:
 *
 *   admin_id       int(11)      NOT NULL, PRIMARY KEY
 *   admin_username varchar(255) NOT NULL
 *   admin_password varchar(255) NOT NULL
 *
 * Three deliberate differences from her dump, all flagged in the merge request
 * for her to review rather than slipped in quietly:
 *
 * 1. autoIncrement on admin_id. Her dump has the primary key but no
 *    AUTO_INCREMENT, which means the second insert through /admins/add
 *    collides on admin_id = 0.
 * 2. A unique index on admin_username. Without it nothing stops two accounts
 *    sharing a username, and the Authentication identifier would then resolve
 *    whichever row the database happened to return first.
 * 3. admin_id is unsigned here, where hers is a signed int(11). I made it
 *    unsigned so it matches contact_submissions.read_by and audit_logs.user_id,
 *    which both point at this column and which I already declared unsigned. A
 *    signed and an unsigned int compare fine in MySQL, so an existing database
 *    is not broken by the difference, but leaving them mismatched would be an
 *    easy thing to trip over later.
 *
 * IMPORTANT for anyone who already has the table: this migration will try to
 * CREATE it and fail. Tell Phinx it is already applied instead:
 *
 *   bin\cake migrations mark_migrated --target=20260820000001 --only
 *
 * Then add the two changes above by hand if you want them locally:
 *
 *   ALTER TABLE admins MODIFY admin_id INT NOT NULL AUTO_INCREMENT;
 *   ALTER TABLE admins ADD UNIQUE KEY uq_admins_username (admin_username);
 *
 * A fresh clone, and the SQLite test database, get all of it automatically.
 */
class CreateAdmins extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('admins', [
            'id' => false,
            'primary_key' => ['admin_id'],
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            // 'integer' rather than 'biginteger' for the same reason as
            // audit_logs.id: CakePHP's SQLite dialect only emits
            // "PRIMARY KEY AUTOINCREMENT" for TYPE_INTEGER, so a biginteger
            // identity column silently comes out with no key at all under
            // test. See the note in CreateAuditLogs.
            ->addColumn('admin_id', 'integer', [
                'autoIncrement' => true,
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('admin_username', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            // This holds a bcrypt hash, never a plaintext password. 255 is
            // more than the 60 characters bcrypt produces, which leaves room
            // if we ever move to Argon2id.
            ->addColumn('admin_password', 'string', [
                'limit' => 255,
                'null' => false,
            ])

            ->addIndex(['admin_username'], [
                'name' => 'uq_admins_username',
                'unique' => true,
            ])

            ->create();
    }
}
