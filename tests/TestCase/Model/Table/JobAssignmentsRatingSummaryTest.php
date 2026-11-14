<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/** The per-plumber average and count of customer ratings the manager sees. */
class JobAssignmentsRatingSummaryTest extends TestCase
{
    protected array $fixtures = [
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
    ];

    private int $nextJobId = 100;

    /** Adds a completed job done by $plumberId, with $rating (or none) from the customer. */
    private function addJob(int $plumberId, ?int $rating, string $status = 'completed'): void
    {
        $jobId = $this->nextJobId++;
        $connection = TableRegistry::getTableLocator()->get('JobRequests')->getConnection();
        $connection->insert('job_requests', [
            'id' => $jobId,
            'customer_id' => 1,
            'street_address' => '1 Test Street',
            'suburb' => 'Clayton',
            'state' => 'VIC',
            'postcode' => '3168',
            'job_description' => 'A tap is leaking under the sink.',
            'preferred_datetime' => '2026-10-01 00:00:00',
            'contact_phone' => '0412 345 678',
            'status' => $status,
            'customer_rating' => $rating,
            'rated_at' => $rating === null ? null : '2026-10-02 03:00:00',
            'created' => '2026-09-30 00:00:00',
            'modified' => '2026-10-02 03:00:00',
        ]);
        $connection->insert('job_assignments', [
            'job_request_id' => $jobId,
            'plumber_admin_id' => $plumberId,
            'assigned_by' => 1,
            'scheduled_start' => '2026-10-01 23:00:00',
            'scheduled_end' => '2026-10-02 01:00:00',
            'created' => '2026-09-30 00:00:00',
            'modified' => '2026-09-30 00:00:00',
        ]);
    }

    public function testEachPlumberGetsTheirOwnAverageAndCount(): void
    {
        foreach ([5, 4, 3] as $rating) {
            $this->addJob(2, $rating);
        }
        $this->addJob(3, 2);

        $summary = TableRegistry::getTableLocator()->get('JobAssignments')->ratingSummary([2, 3]);

        $this->assertEqualsWithDelta(4.0, $summary[2]['average'], 0.0001);
        $this->assertSame(3, $summary[2]['count']);
        $this->assertEqualsWithDelta(2.0, $summary[3]['average'], 0.0001);
        $this->assertSame(1, $summary[3]['count']);
    }

    public function testTheAverageIsNotRoundedAwayBeforeItReachesThePage(): void
    {
        foreach ([5, 4, 4] as $rating) {
            $this->addJob(2, $rating);
        }

        $summary = TableRegistry::getTableLocator()->get('JobAssignments')->ratingSummary([2]);

        $this->assertEqualsWithDelta(13 / 3, $summary[2]['average'], 0.0001);
    }

    public function testUnratedJobsAreLeftOutOfTheAverageAndTheCount(): void
    {
        $this->addJob(2, 5);
        $this->addJob(2, null);
        $this->addJob(2, null, 'unable_to_complete');

        $summary = TableRegistry::getTableLocator()->get('JobAssignments')->ratingSummary([2]);

        $this->assertEqualsWithDelta(5.0, $summary[2]['average'], 0.0001);
        $this->assertSame(1, $summary[2]['count']);
    }

    public function testAPlumberWithNoRatingsStillGetsAnEntry(): void
    {
        $this->addJob(2, 4);
        $this->addJob(3, null);

        $summary = TableRegistry::getTableLocator()->get('JobAssignments')->ratingSummary([2, 3, 4]);

        $this->assertSame([2, 3, 4], array_keys($summary));
        $this->assertSame(['average' => null, 'count' => 0], $summary[3]);
        $this->assertSame(['average' => null, 'count' => 0], $summary[4]);
    }

    public function testOnlyTheRequestedPlumbersAreReturned(): void
    {
        $this->addJob(2, 5);
        $this->addJob(3, 1);

        $summary = TableRegistry::getTableLocator()->get('JobAssignments')->ratingSummary([2]);

        $this->assertSame([2], array_keys($summary));
        $this->assertSame(1, $summary[2]['count']);
    }

    public function testNoPlumbersMeansNoQuery(): void
    {
        $this->addJob(2, 5);

        $this->assertSame([], TableRegistry::getTableLocator()->get('JobAssignments')->ratingSummary([]));
    }
}
