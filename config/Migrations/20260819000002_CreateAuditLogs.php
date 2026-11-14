<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * audit_logs
 *
 * My append-only record of privileged actions. This is how I cover the
 * Repudiation leg of the STRIDE model I worked through.
 *
 * I named it audit_logs rather than audit_log because CakePHP's ORM
 * conventions expect a plural table name; AuditLogsTable would not find a
 * singular one without an explicit setTable() call.
 *
 * I would grant the application database user INSERT and SELECT here and NOT
 * UPDATE or DELETE, so a compromised app account cannot rewrite its own
 * trail.
 */
class CreateAuditLogs extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('audit_logs', [
            'id' => false,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            // 'integer', not 'biginteger'. I had biginteger here and it cost
            // me a debugging session. CakePHP's SQLite dialect only emits
            // "PRIMARY KEY AUTOINCREMENT" for TYPE_INTEGER, in
            // SqliteSchemaDialect::columnDefinitionSql. A biginteger identity
            // column comes out as a plain "BIGINT NOT NULL" with no key, so
            // every insert relying on a generated id failed the NOT NULL
            // constraint, and my audit trail was silently empty under test.
            // MySQL was fine either way; INT UNSIGNED is 4.29 billion rows,
            // far past anything this table will hold.
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'signed' => false,
                'null' => false,
            ])
            // Integer, matching admins.admin_id. See CreateContactSubmissions
            // for why I have to keep this tracking the identity model.
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            // For example submission.status_change or submission.export.
            ->addColumn('action', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('entity_type', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('entity_id', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
            ])
            // A small JSON blob. I keep credentials and full message bodies
            // out of it, because I did not want the log becoming a second copy
            // of the customer data.
            ->addColumn('detail', 'text', [
                'null' => true,
                'default' => null,
            ])
            // I already mask this to a /24 in AuditLogsTable::record().
            ->addColumn('ip_address', 'string', [
                'limit' => 45,
                'null' => true,
                'default' => null,
            ])
            // Set by the Timestamp behaviour on AuditLogsTable rather than a
            // SQL default, which would not port to my SQLite test connection.
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])

            ->addIndex(['user_id', 'created'], [
                'name' => 'idx_audit_user_date',
            ])
            ->addIndex(['action', 'created'], [
                'name' => 'idx_audit_action_date',
            ])

            ->create();
    }
}
