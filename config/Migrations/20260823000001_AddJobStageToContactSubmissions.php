<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Adds a job stage to enquiries.
 *
 * US 3.3 and 3.4 both talk about a job proceeding to the next stage, and
 * nothing in the app had a concept of one. `status` looks like it might, but
 * new/read/archived answers "has an admin dealt with this message", which is
 * triage. Whether the work has been approved and scheduled is a different
 * question with a different lifecycle, and US 2.4 already has tests resting on
 * the meaning of `status`.
 *
 * So a separate column. An enquiry can be read and still unapproved; a job can
 * be scheduled and its enquiry archived. Folding both into one field would
 * have forced those to move together and broken the US 2.4 tests to boot.
 *
 * Every existing row starts at 'enquiry', which is where the compliance gate
 * sits. Nothing is auto-approved by this migration: an enquiry becoming
 * approved has to be a decision someone made, and defaulting the other way
 * would mean the entire back catalogue silently cleared compliance nobody
 * checked.
 *
 * Remember to run `bin\cake cache clear_all` after this. CakePHP caches table
 * descriptions for a year, so without it the ORM keeps selecting the old
 * column list and job_stage reads back as null everywhere.
 */
class AddJobStageToContactSubmissions extends BaseMigration
{
    public function up(): void
    {
        $this->table('contact_submissions')
            ->addColumn('job_stage', 'string', [
                'limit' => 20,
                'default' => 'enquiry',
                'null' => false,
                'after' => 'status',
            ])
            // The list page filters on this, and it pairs with the existing
            // date sort, so the index covers both together.
            ->addIndex(['job_stage', 'submitted_at'], [
                'name' => 'idx_submissions_stage_date',
            ])
            ->update();

        $this->execute("UPDATE contact_submissions SET job_stage = 'enquiry'");
    }

    public function down(): void
    {
        $this->table('contact_submissions')
            ->removeIndexByName('idx_submissions_stage_date')
            ->removeColumn('job_stage')
            ->update();
    }
}
