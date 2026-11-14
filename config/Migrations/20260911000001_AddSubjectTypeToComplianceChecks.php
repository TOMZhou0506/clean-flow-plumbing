<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Lets a compliance checklist hang off a job request as well as an enquiry.
 *
 * Until now compliance only applied to contact_submissions, which was the
 * wrong half of the system. A customer job request is the thing that actually
 * needs a licence checked and a permit sighted; a contact enquiry is often
 * just somebody asking a question. Both need the checklist, so the table now
 * records which kind of record a row belongs to.
 *
 * I kept the column name `submission_id` rather than renaming it to something
 * neutral. The rename would have been more honest, but it reaches about thirty
 * call sites including Suhana's new advance() action, and a rename plus a
 * backfill on a table with live rows is two risky things at once. The column
 * holds an id; `subject_type` says what it is an id of. There is a follow-up
 * note in docs/HANDOFF.md to rename it when nothing else is in flight.
 *
 * Every existing row is an enquiry, so the default backfills them correctly
 * and nothing already recorded is lost.
 *
 * The unique index has to be rebuilt. It was (submission_id, requirement),
 * which would now collide the moment enquiry 7 and job request 7 both had a
 * licence row: same pair, different records. It becomes
 * (subject_type, submission_id, requirement).
 *
 * Run `bin\cake cache clear_all` after this. The schema cache lasts a year, so
 * without it the ORM will not know subject_type exists and every read comes
 * back null.
 */
class AddSubjectTypeToComplianceChecks extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('compliance_checks');

        $table
            ->addColumn('subject_type', 'string', [
                'limit' => 20,
                'default' => 'enquiry',
                'null' => false,
                'after' => 'id',
            ])
            ->update();

        // Existing rows all came from the enquiry dashboard.
        $this->execute("UPDATE compliance_checks SET subject_type = 'enquiry'");

        // Rebuild the uniqueness rule so it spans the type as well.
        $this->table('compliance_checks')
            ->removeIndexByName('uq_compliance_submission_requirement')
            ->addIndex(['subject_type', 'submission_id', 'requirement'], [
                'name' => 'uq_compliance_subject_requirement',
                'unique' => true,
            ])
            ->update();
    }

    public function down(): void
    {
        // Dropping the column would silently merge enquiry and job request
        // rows that share an id, and the unique index would then reject the
        // collision rather than telling anyone why. So this refuses to run
        // while job request rows exist.
        $rows = $this->fetchAll(
            "SELECT COUNT(*) AS c FROM compliance_checks WHERE subject_type = 'job_request'",
        );
        $count = (int)($rows[0]['c'] ?? 0);

        if ($count > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to roll back: %d job request compliance rows would be orphaned. '
                . 'Delete them first if you really mean to.',
                $count,
            ));
        }

        $this->table('compliance_checks')
            ->removeIndexByName('uq_compliance_subject_requirement')
            ->addIndex(['submission_id', 'requirement'], [
                'name' => 'uq_compliance_submission_requirement',
                'unique' => true,
            ])
            ->removeColumn('subject_type')
            ->update();
    }
}
