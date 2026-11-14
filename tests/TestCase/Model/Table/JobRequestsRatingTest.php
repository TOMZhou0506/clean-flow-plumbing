<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\JobRequest;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/** The star rating a customer gives a completed job. */
class JobRequestsRatingTest extends TestCase
{
    protected array $fixtures = [
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
    ];

    /** Job 1 belongs to customer 1, job 2 to customer 2. */
    private function complete(int $jobId, bool $withPlumber = true): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get($jobId);
        $job->set('status', JobRequest::STATUS_COMPLETED);
        $jobs->saveOrFail($job);

        if ($withPlumber) {
            $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
            $assignments->saveOrFail($assignments->newEntity([
                'job_request_id' => $jobId,
                'plumber_admin_id' => 2,
                'assigned_by' => 1,
                'scheduled_start' => '2026-10-01 23:00:00',
                'scheduled_end' => '2026-10-02 01:00:00',
            ]));
        }
    }

    public function testOnlyWholeNumbersFromOneToFiveAreAccepted(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');

        foreach ([1, 2, 3, 4, 5, '1', '2', '3', '4', '5'] as $good) {
            $this->assertSame((int)$good, $jobs->parseRating($good), var_export($good, true));
        }
        foreach (
            [
                0, 6, -1, 100, '0', '6', '-1', '2.5', '03', ' 3', '3 ', '1e0', 'five', '', null, 2.5, 3.0,
                true, false, [], ['3'], '３',
            ] as $bad
        ) {
            $this->assertNull($jobs->parseRating($bad), var_export($bad, true));
        }
    }

    public function testCustomerCanRateTheirCompletedJob(): void
    {
        $this->complete(1);
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');

        $this->assertTrue($jobs->rateCompletedJob(1, 1, 4));

        $job = $jobs->get(1);
        $this->assertSame(4, (int)$job->customer_rating);
        $this->assertNotNull($job->rated_at);
        $this->assertTrue($job->isRated());
    }

    public function testASecondRatingIsRefusedAndTheFirstIsKept(): void
    {
        $this->complete(1);
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $this->assertTrue($jobs->rateCompletedJob(1, 1, 4));

        $this->assertFalse($jobs->rateCompletedJob(1, 1, 1));
        $this->assertSame(4, (int)$jobs->get(1)->customer_rating);
    }

    public function testAJobThatIsNotCompletedCannotBeRated(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');

        foreach (
            [
                JobRequest::STATUS_SUBMITTED,
                JobRequest::STATUS_ASSIGNED,
                JobRequest::STATUS_IN_PROGRESS,
                JobRequest::STATUS_UNABLE_TO_COMPLETE,
            ] as $status
        ) {
            $job = $jobs->get(1);
            $job->set('status', $status);
            $jobs->saveOrFail($job);

            $this->assertFalse($jobs->rateCompletedJob(1, 1, 5), $status);
            $this->assertNull($jobs->get(1)->customer_rating, $status);
        }
    }

    public function testACustomerCannotRateAnotherCustomersJob(): void
    {
        $this->complete(2);
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');

        $this->assertFalse($jobs->rateCompletedJob(2, 1, 5));
        $this->assertNull($jobs->get(2)->customer_rating);
    }

    public function testACompletedJobWithoutAPlumberCannotBeRated(): void
    {
        $this->complete(1, false);
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');

        $this->assertFalse($jobs->rateCompletedJob(1, 1, 5));
        $this->assertNull($jobs->get(1)->customer_rating);
    }

    public function testARatingOutsideOneToFiveIsRejectedBeforeAnythingIsWritten(): void
    {
        $this->complete(1);
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');

        foreach ([0, 6, -3] as $bad) {
            try {
                $jobs->rateCompletedJob(1, 1, $bad);
                $this->fail('Expected a rating of ' . $bad . ' to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertNull($jobs->get(1)->customer_rating);
            }
        }
    }
}
