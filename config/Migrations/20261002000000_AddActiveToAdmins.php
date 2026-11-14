<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Keep former office accounts for audit attribution without letting them log in.
 * The existing role column already has room for the new "manager" value.
 */
class AddActiveToAdmins extends BaseMigration
{
    /** Add a non-destructive account deactivation flag. */
    public function change(): void
    {
        $this->table('admins')
            ->addColumn('active', 'boolean', [
                'default' => true,
                'null' => false,
                'after' => 'role',
            ])
            ->update();
    }
}
