<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Gives job requests the same read tracking enquiries already have.
 *
 * The office asked for the job request screen to work like the enquiry
 * dashboard, and a large part of what makes that screen usable is knowing at a
 * glance which rows nobody has opened yet. Enquiries have had read_at and
 * read_by since US 2.4; this brings job requests level.
 *
 * read_by is an unsigned integer pointing at admins.admin_id, matching
 * contact_submissions.read_by and audit_logs.user_id. It stays nullable
 * because a row can be read before we know who did it, and because deleting an
 * admin account should not delete the request they happened to open.
 *
 * The index covers the default list query, which sorts by created date and
 * filters by status.
 *
 * Run `bin\cake cache clear_all` afterwards or the ORM will not see either
 * column, and every read_at check will come back null.
 */
class AddReadTrackingToJobRequests extends BaseMigration
{
    public function up(): void
    {
        $this->table('job_requests')
            ->addColumn('read_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'status',
            ])
            ->addColumn('read_by', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'read_at',
            ])
            ->addIndex(['status', 'created'], [
                'name' => 'idx_job_requests_status_date',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('job_requests')
            ->removeIndexByName('idx_job_requests_status_date')
            ->removeColumn('read_by')
            ->removeColumn('read_at')
            ->update();
    }
}
