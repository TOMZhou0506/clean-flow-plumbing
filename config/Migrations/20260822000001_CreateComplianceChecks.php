<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * compliance_checks
 *
 * One row per requirement per enquiry, for US 3.1.
 *
 * I store a row only once someone has actually recorded a decision. An
 * enquiry with no rows is not "failing compliance", it is "nobody has looked
 * yet", and those are different things Hannah needs to tell apart. The
 * template fills the gaps from ComplianceCheck::REQUIREMENTS so the checklist
 * always shows every item.
 *
 * I did not put the requirement list in a table of its own. It is fixed for
 * this iteration, and a lookup table would mean a second screen to manage it,
 * a foreign key, and a seeding step, for no behaviour we need yet. Keeping
 * the keys in a class constant means the allowed values are visible in code
 * review and cannot drift out of sync with the labels. If the client later
 * wants to edit the list themselves, that is the point to add the table.
 *
 * The unique index on (submission_id, requirement) is the part that matters.
 * Without it a double-submitted form writes two rows for the same
 * requirement, and which one the page shows depends on row order.
 */
class CreateComplianceChecks extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('compliance_checks', [
            'id' => false,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            // 'integer' not 'biginteger', for the SQLite autoincrement reason
            // recorded in CreateAuditLogs.
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('submission_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            // The requirement key, matching ComplianceCheck::REQUIREMENTS.
            // A string rather than a foreign key, see the note above.
            ->addColumn('requirement', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('is_met', 'boolean', [
                'default' => false,
                'null' => false,
            ])
            // Free text from an admin. Escaped on output, never rendered as
            // HTML. See the comment in templates/Compliance/view.php.
            ->addColumn('notes', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('checked_by', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('checked_at', 'datetime', [
                'null' => false,
            ])

            // One decision per requirement per enquiry.
            ->addIndex(['submission_id', 'requirement'], [
                'name' => 'uq_compliance_submission_requirement',
                'unique' => true,
            ])

            ->create();
    }
}
