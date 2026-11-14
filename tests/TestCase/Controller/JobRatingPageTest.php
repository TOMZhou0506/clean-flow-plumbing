<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\JobRequest;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DOMDocument;
use DOMXPath;

/** What the job details page shows a customer about rating, in each state of a job. */
class JobRatingPageTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins', 'app.Customers', 'app.JobRequests', 'app.JobAssignments',
    ];

    private function signInAsCustomer(int $id = 1): void
    {
        $this->session(['CustomerAuth' => [
            'cust_id' => $id,
            'cust_email' => $id === 1 ? 'kash@example.com' : 'other@example.com',
            'cust_fname' => $id === 1 ? 'Kash' : 'Other',
            'cust_lname' => $id === 1 ? 'Patel' : 'Customer',
        ]]);
    }

    /** Job 1 belongs to customer 1 and job 2 to customer 2. */
    private function putJobIn(int $jobId, string $status, ?int $rating = null, bool $withPlumber = true): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get($jobId);
        $job->set('status', $status);
        $jobs->saveOrFail($job);
        if ($rating !== null) {
            $jobs->updateAll(['customer_rating' => $rating, 'rated_at' => '2026-10-02 03:00:00'], ['id' => $jobId]);
        }

        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        if ($withPlumber && $assignments->find()->where(['job_request_id' => $jobId])->count() === 0) {
            $assignments->saveOrFail($assignments->newEntity([
                'job_request_id' => $jobId,
                'plumber_admin_id' => 2,
                'assigned_by' => 1,
                'scheduled_start' => '2026-10-01 23:00:00',
                'scheduled_end' => '2026-10-02 01:00:00',
            ]));
        }
    }

    public function testACompletedUnratedJobShowsTheRatingForm(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED);
        $this->signInAsCustomer();

        $this->get('/job-requests/confirmation/1');

        $this->assertResponseOk();
        $this->assertResponseContains('Rate this job');
        $this->assertResponseContains('action="/job-requests/rate/1"');
        $this->assertResponseContains('Submit rating');
        foreach ([1, 2, 3, 4, 5] as $stars) {
            $this->assertResponseContains('id="rating-' . $stars . '" value="' . $stars . '"');
        }
        $this->assertResponseNotContains('You rated this job');
    }

    public function testNoRatingFormIsShownUnlessTheJobIsCompleted(): void
    {
        $this->signInAsCustomer();

        foreach (
            [
                JobRequest::STATUS_SUBMITTED,
                JobRequest::STATUS_REVIEWING,
                JobRequest::STATUS_APPROVED,
                JobRequest::STATUS_DECLINED,
                JobRequest::STATUS_ASSIGNED,
                JobRequest::STATUS_IN_PROGRESS,
                JobRequest::STATUS_UNABLE_TO_COMPLETE,
            ] as $status
        ) {
            $this->putJobIn(1, $status, null, true);
            $this->get('/job-requests/confirmation/1');

            $this->assertResponseOk();
            $this->assertResponseNotContains('Rate this job', $status);
            $this->assertResponseNotContains('data-rating-section', $status);
            $this->assertResponseNotContains('Submit rating', $status);
        }
    }

    public function testACompletedJobWithoutAPlumberShowsNoRatingForm(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED, null, false);
        $this->signInAsCustomer();

        $this->get('/job-requests/confirmation/1');

        $this->assertResponseOk();
        $this->assertResponseNotContains('Rate this job');
        $this->assertResponseNotContains('data-rating-section');
    }

    public function testARatedJobShowsTheCustomersOwnRatingAndNoForm(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED, 4);
        $this->signInAsCustomer();

        $this->get('/job-requests/confirmation/1');

        $this->assertResponseOk();
        $this->assertResponseContains('You rated this job');
        $this->assertResponseContains('★★★★☆');
        $this->assertResponseContains('4 out of 5 stars');
        $this->assertResponseNotContains('Rate this job');
        $this->assertResponseNotContains('Submit rating');
        $this->assertResponseNotContains('action="/job-requests/rate/1"');
    }

    public function testAnotherCustomersRatingIsNeverShown(): void
    {
        $this->putJobIn(2, JobRequest::STATUS_COMPLETED, 5);
        $this->signInAsCustomer(1);

        $this->get('/job-requests/confirmation/1');
        $this->assertResponseOk();
        $this->assertResponseNotContains('★★★★★');
        $this->assertResponseNotContains('You rated this job');

        $this->get('/job-requests');
        $this->assertResponseOk();
        $this->assertResponseNotContains('★★★★★');
        $this->assertResponseNotContains('5 out of 5 stars');
    }

    public function testTheRatingSectionIsOutsideTheSelfRefreshingRegion(): void
    {
        foreach ([null, 3] as $rating) {
            $this->putJobIn(1, JobRequest::STATUS_COMPLETED, $rating, $rating === null);
            $this->signInAsCustomer();
            $this->get('/job-requests/confirmation/1');
            $this->assertResponseOk();

            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>' . $this->_getBodyAsString());
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);

            $this->assertSame(1, $xpath->query('//*[@data-rating-section]')->length);
            $this->assertSame(
                0,
                $xpath->query('//*[@data-live-region]//*[@data-rating-section]')->length,
                'A rating form inside the live region would be wiped every 30 seconds.',
            );
        }
    }
}
