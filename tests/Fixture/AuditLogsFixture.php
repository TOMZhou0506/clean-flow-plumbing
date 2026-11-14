<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * AuditLogsFixture
 *
 * I left this empty on purpose. Several of my tests assert that an action
 * writes an audit entry, and testExportIsAudited counts rows matching
 * 'submission.export', so seeding anything here would make those counts
 * wrong.
 */
class AuditLogsFixture extends TestFixture
{
    /**
     * @var array<array<string, mixed>>
     */
    public array $records = [];
}
