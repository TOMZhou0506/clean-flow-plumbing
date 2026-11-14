<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/** Stores each admin's acknowledgement of a plumber availability change. */
class CreateAvailabilityAlertDismissals extends BaseMigration
{
    /** Create per-admin acknowledgement storage. */
    public function change(): void
    {
        $this->table('availability_alert_dismissals')
            ->addColumn('admin_id', 'integer', ['null' => false])
            ->addColumn('audit_log_id', 'integer', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['admin_id', 'audit_log_id'], ['unique' => true, 'name' => 'uq_availability_dismissal'])
            ->create();
    }
}
