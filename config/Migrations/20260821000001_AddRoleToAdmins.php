<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Adds a role to admins.
 *
 * US 2.4 asks us to prove that a non-admin cannot reach the requests list.
 * Until now every account in this table had identical rights, so that test
 * could not be run at all: there was no such thing as a non-admin account.
 * This column is what makes the check real rather than assumed.
 *
 * I default new rows to 'staff', not 'admin'. If I get the default wrong in
 * the safe direction someone is locked out and tells me. Wrong in the other
 * direction and every account silently gains access to customer data, and
 * nobody reports that because nothing appears broken.
 *
 * The existing rows are a different matter: defaulting them to 'staff' would
 * lock the team out of their own dashboard mid-iteration, so the update
 * statement below promotes the accounts that already exist. Anyone created
 * after this migration starts as staff and is promoted deliberately.
 */
class AddRoleToAdmins extends BaseMigration
{
    public function up(): void
    {
        $this->table('admins')
            ->addColumn('role', 'string', [
                'limit' => 20,
                'default' => 'staff',
                'null' => false,
                'after' => 'admin_password',
            ])
            ->addIndex(['role'], ['name' => 'idx_admins_role'])
            ->update();

        // Everyone who already had an account keeps the access they had
        // yesterday. Without this the team cannot log in to the dashboard
        // after pulling this branch.
        $this->execute("UPDATE admins SET role = 'admin'");
    }

    public function down(): void
    {
        $this->table('admins')
            ->removeIndexByName('idx_admins_role')
            ->removeColumn('role')
            ->update();
    }
}
