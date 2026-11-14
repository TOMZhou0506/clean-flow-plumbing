<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/** Fleet records are retained when a vehicle is taken out of service. */
class CreateVehicles extends BaseMigration
{
    /** Create the fleet register independently of job allocation. */
    public function up(): void
    {
        if ($this->hasTable('vehicles')) {
            return;
        }

        $this->table('vehicles', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('registration_number', 'string', ['limit' => 15, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('notes', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('active', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['registration_number'], ['unique' => true, 'name' => 'uq_vehicles_registration'])
            ->create();
    }

    /** Remove only on an explicit migration rollback. */
    public function down(): void
    {
        $this->table('vehicles')->drop()->save();
    }
}
