<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * ComplianceChecksFixture
 *
 * Submission 1 is partly reviewed, so the "blocked" verdict and the
 * outstanding count have something real to report. Submission 2 has a note
 * carrying a script tag, so the escaping claim in US 3.1 can be demonstrated
 * rather than asserted.
 *
 * Row 4 is deliberately job request 1 with the same requirement as enquiry 1.
 * The two share an id, which is exactly the collision that would go unnoticed
 * if subject_type were ever dropped from a query: an enquiry would start
 * showing a job request's checks. Every test that counts checks for enquiry 1
 * is counting against this row sitting next to it.
 */
class ComplianceChecksFixture extends TestFixture
{
    /**
     * The payload used to prove admin notes are escaped on output.
     *
     * @var string
     */
    public const XSS_NOTE = '<script>alert("compliance")</script>';

    public array $records = [
        [
            'id' => 1,
            'subject_type' => 'enquiry',
            'submission_id' => 1,
            'requirement' => 'licence',
            'is_met' => true,
            'notes' => 'Licence 12345 verified against the VBA register.',
            'checked_by' => 1,
            'checked_at' => '2026-08-17 01:00:00',
        ],
        [
            'id' => 2,
            'subject_type' => 'enquiry',
            'submission_id' => 1,
            'requirement' => 'safety',
            'is_met' => false,
            'notes' => 'Site visit not booked yet.',
            'checked_by' => 1,
            'checked_at' => '2026-08-17 01:05:00',
        ],
        [
            'id' => 3,
            'subject_type' => 'enquiry',
            'submission_id' => 2,
            'requirement' => 'licence',
            'is_met' => true,
            'notes' => self::XSS_NOTE,
            'checked_by' => 1,
            'checked_at' => '2026-08-17 02:00:00',
        ],
        [
            'id' => 4,
            'subject_type' => 'job_request',
            'submission_id' => 1,
            'requirement' => 'licence',
            'is_met' => true,
            'notes' => 'Plumber licence sighted for the Clayton Road job.',
            'checked_by' => 1,
            'checked_at' => '2026-09-09 03:00:00',
        ],
    ];
}
