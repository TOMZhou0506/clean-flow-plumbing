<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Stores short-lived counters used to throttle login attempts.
 */
class CreateLoginRateLimits extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('login_rate_limits', [
            'id' => false,
            'primary_key' => ['rate_key'],
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('rate_key', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('scope', 'string', [
                'limit' => 20,
                'null' => false,
            ])
            ->addColumn('attempts', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
            ])
            ->addColumn('expires_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addIndex(['expires_at'], [
                'name' => 'idx_login_rate_limits_expiry',
            ])
            ->create();
    }
}
