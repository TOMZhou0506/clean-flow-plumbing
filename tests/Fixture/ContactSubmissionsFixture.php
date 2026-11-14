<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * ContactSubmissionsFixture
 *
 * Rows 4 and 5 are security fixtures, not filler. They exist so the XSS and
 * CSV-injection claims in docs/SECURITY.md can be demonstrated rather than
 * asserted.
 */
class ContactSubmissionsFixture extends TestFixture
{
    public array $records = [
        [
            'id' => 1,
            'name' => 'Marcus Bell',
            'email' => 'marcus.bell@example.com',
            'phone' => '0412 555 013',
            'subject' => 'Burst pipe under kitchen sink',
            'message' => 'Water is pooling under the sink and the shutoff valve is seized.',
            'status' => 'new',
            'read_at' => null,
            'read_by' => null,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0',
            'submitted_at' => '2026-08-16 08:12:00',
        ],
        [
            'id' => 2,
            'name' => 'Priya Raghavan',
            'email' => 'p.raghavan@example.com',
            'phone' => '0433 221 908',
            'subject' => 'Hot water system quote',
            'message' => 'Our unit is 14 years old and taking a long time to heat.',
            'status' => 'read',
            'read_at' => '2026-08-16 10:00:00',
            'read_by' => 1,
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Mozilla/5.0',
            'submitted_at' => '2026-08-16 09:47:00',
        ],
        [
            'id' => 3,
            'name' => 'Tom Whitfield',
            'email' => 'tw@example.com',
            'phone' => null,
            'subject' => 'Drainage - recurring blockage',
            'message' => 'Third blockage in the back drain this year.',
            'status' => 'archived',
            'read_at' => '2026-08-15 16:20:00',
            'read_by' => 1,
            'ip_address' => null,
            'user_agent' => null,
            'submitted_at' => '2026-08-15 14:03:00',
        ],
        [
            // Stored XSS fixture. Must render as literal text everywhere.
            'id' => 4,
            'name' => '<img src=x onerror=alert(1)>',
            'email' => 'xss.test@example.com',
            'phone' => null,
            'subject' => '"><script>alert(document.cookie)</script>',
            'message' => 'If any part of this row executes, output encoding has failed.',
            'status' => 'new',
            'read_at' => null,
            'read_by' => null,
            'ip_address' => null,
            'user_agent' => null,
            'submitted_at' => '2026-08-01 09:00:00',
        ],
        [
            // CSV injection fixture. Must not evaluate when opened in Excel.
            'id' => 5,
            'name' => "=cmd|'/c calc'!A1",
            'email' => 'csv.test@example.com',
            'phone' => '+61400000000',
            'subject' => '@SUM(1+1)*cmd',
            'message' => 'Export this row and open it in Excel. It must show as text.',
            'status' => 'new',
            'read_at' => null,
            'read_by' => null,
            'ip_address' => null,
            'user_agent' => null,
            'submitted_at' => '2026-08-01 09:05:00',
        ],
    ];
}
