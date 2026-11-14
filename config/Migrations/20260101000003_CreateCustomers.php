<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Backfills the customer table that originally existed only in the shared SQL database.
 */
class CreateCustomers extends BaseMigration
{
    /**
     * Create the table only when the manually provisioned table is absent.
     *
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('customer')) {
            return;
        }

        $this->table('customer', [
            'id' => false,
            'primary_key' => ['cust_id'],
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('cust_id', 'integer', [
                'autoIncrement' => true,
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('cust_email', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('cust_fname', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('cust_lname', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('cust_phone', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('cust_address', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('cust_type', 'string', [
                'limit' => 30,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('cust_password_hash', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addIndex(['cust_email'], [
                'name' => 'uq_customer_email',
                'unique' => true,
            ])
            ->create();
    }

    /**
     * Preserve customer accounts created before migrations were introduced.
     *
     * @return void
     */
    public function down(): void
    {
        // The migration describes a pre-existing shared table; rollback must not delete customer accounts.
    }
}
