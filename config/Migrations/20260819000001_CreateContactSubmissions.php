<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * contact_submissions
 *
 * Shared table. The public contact form inserts here and my dashboard reads
 * and updates status. Whoever finishes the contact form needs to match these
 * column names.
 *
 * Run with:  bin/cake migrations migrate
 * Roll back: bin/cake migrations rollback
 */
class CreateContactSubmissions extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('contact_submissions', [
            'id' => false,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'signed' => false,
                'null' => false,
            ])

            // ---- written by the contact form ----
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('email', 'string', [
                'limit' => 254,
                'null' => false,
            ])
            ->addColumn('phone', 'string', [
                'limit' => 32,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('subject', 'string', [
                'limit' => 150,
                'null' => true,
                'default' => null,
            ])
            // I used TEXT rather than VARCHAR. Customers write paragraphs,
            // and a truncated enquiry is worse than a long one.
            ->addColumn('message', 'text', [
                'null' => false,
            ])

            // ---- managed by the dashboard ----
            // I wanted an enum here and could not have one: migrations 5.x
            // dropped the type, and my tests run on SQLite, which has no enum
            // at all. I enforce the allowed values in the application layer
            // instead, through ContactSubmission::STATUSES. It is the more
            // portable choice anyway, since changing an enum's members in
            // MySQL rewrites the whole table.
            ->addColumn('status', 'string', [
                'limit' => 20,
                'default' => 'new',
                'null' => false,
            ])
            ->addColumn('read_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            // Integer, matching admins.admin_id. I had this as uuid while the
            // dashboard authenticated against users.id, then we moved the
            // identity model to admins, whose primary key is an int.
            //
            // This type has to track whichever table the identity comes from.
            // A mismatch does not error, it silently writes null, and the
            // column recording who opened an enquiry stays empty.
            ->addColumn('read_by', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])

            // ---- abuse forensics, written by the contact form ----
            // Personal data under the Privacy Act. I store it as a string
            // rather than VARBINARY so the Cake ORM handles it without a
            // custom type. 45 characters covers a full IPv6 address.
            ->addColumn('ip_address', 'string', [
                'limit' => 45,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('user_agent', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
            ])

            // No SQL-level default. CURRENT_TIMESTAMP does not port to the
            // SQLite connection my tests use, and the Timestamp behaviour on
            // ContactSubmissionsTable sets this on create anyway.
            ->addColumn('submitted_at', 'datetime', [
                'null' => false,
            ])

            // Covers my default view: filter by status, sort by date.
            ->addIndex(['status', 'submitted_at'], [
                'name' => 'idx_submissions_status_date',
            ])
            // Covers my unfiltered list and the date-range filter.
            ->addIndex(['submitted_at'], [
                'name' => 'idx_submissions_date',
            ])
            // Lets the client find every enquiry from one customer.
            ->addIndex(['email'], [
                'name' => 'idx_submissions_email',
            ])

            ->create();
    }
}
