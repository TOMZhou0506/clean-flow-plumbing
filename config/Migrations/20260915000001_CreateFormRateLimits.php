<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Stores short-lived counters for public form submissions.
 */
class CreateFormRateLimits extends BaseMigration
{
    public function change(): void
    {
        $this->table('form_rate_limits', [
            'id' => false,
            'primary_key' => ['rate_key'],
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('rate_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('scope', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('attempts', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
            ])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['expires_at'], ['name' => 'idx_form_rate_limits_expiry'])
            ->create();
    }
}
