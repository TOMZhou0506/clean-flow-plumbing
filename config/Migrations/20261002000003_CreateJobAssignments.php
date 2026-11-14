<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/** One scheduled visit per request in the first iteration. Times are UTC. */
class CreateJobAssignments extends BaseMigration
{
    /** Create the single-visit assignment table. */
    public function up(): void
    {
        if ($this->hasTable('job_assignments')) {
            return;
        }

        $this->table('job_assignments')
            ->addColumn('job_request_id', 'integer', ['null' => false])
            ->addColumn('plumber_admin_id', 'integer', ['null' => false])
            ->addColumn('assigned_by', 'integer', ['null' => false])
            ->addColumn('scheduled_start', 'datetime', ['null' => false])
            ->addColumn('scheduled_end', 'datetime', ['null' => false])
            ->addColumn('started_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('finished_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('unable_reason', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['job_request_id'], ['unique' => true, 'name' => 'uq_job_assignment_request'])
            ->addIndex(
                ['plumber_admin_id', 'scheduled_start', 'scheduled_end'],
                ['name' => 'idx_job_assignment_plumber_time'],
            )
            ->create();
    }

    /** Remove only on an explicit rollback. */
    public function down(): void
    {
        $this->table('job_assignments')->drop()->save();
    }
}
