<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Codes for resetting an office account's password.
 *
 * Only a hash of the six digit code is stored, never the code, so nobody who can
 * read the table can use it. A code is valid until `expires`, for a limited number
 * of wrong guesses (`attempts`), and once (`used`).
 */
class CreatePasswordResets extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('password_resets', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('admin_id', 'integer', ['null' => false])
            ->addColumn('code_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('expires', 'datetime', ['null' => false])
            ->addColumn('attempts', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('used', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['admin_id', 'used'], ['name' => 'idx_password_resets_admin_used'])
            ->create();
    }
}
