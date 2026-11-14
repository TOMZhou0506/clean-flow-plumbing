<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/** Existing visits remain valid without a vehicle; new visits require one in the app. */
class AddVehicleToJobAssignments extends BaseMigration
{
    /** Add the optional historical link and its lookup index. */
    public function up(): void
    {
        if (!$this->table('job_assignments')->hasColumn('vehicle_id')) {
            $this->table('job_assignments')
                ->addColumn('vehicle_id', 'integer', ['null' => true, 'default' => null])
                ->addIndex(['vehicle_id', 'scheduled_start', 'scheduled_end'], [
                    'name' => 'idx_job_assignment_vehicle_time',
                ])
                ->update();
        }
    }

    /** Remove only on an explicit rollback. */
    public function down(): void
    {
        $this->table('job_assignments')
            ->removeIndexByName('idx_job_assignment_vehicle_time')
            ->removeColumn('vehicle_id')
            ->update();
    }
}
