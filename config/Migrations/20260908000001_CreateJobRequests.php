<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Stores customer job requests awaiting staff review.
 */
class CreateJobRequests extends BaseMigration
{
    /**
     * Create the job requests table and its customer relationship.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('job_requests', [
            'id' => false,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('customer_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('street_address', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('suburb', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('state', 'string', [
                'limit' => 3,
                'null' => false,
            ])
            ->addColumn('postcode', 'string', [
                'limit' => 4,
                'null' => false,
            ])
            ->addColumn('job_description', 'text', [
                'null' => false,
            ])
            ->addColumn('preferred_datetime', 'datetime', [
                'null' => false,
            ])
            ->addColumn('contact_phone', 'string', [
                'limit' => 20,
                'null' => false,
            ])
            ->addColumn('access_instructions', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('status', 'string', [
                'limit' => 30,
                'default' => 'submitted',
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addIndex(['customer_id', 'created'], [
                'name' => 'idx_job_requests_customer_created',
            ])
            ->addIndex(['status', 'created'], [
                'name' => 'idx_job_requests_status_created',
            ])
            ->addForeignKey('customer_id', 'customer', 'cust_id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_job_requests_customer',
            ])
            ->create();
    }
}
