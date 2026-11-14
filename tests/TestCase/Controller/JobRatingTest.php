<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\JobRequest;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** A customer rates their own completed job from 1 to 5 stars, once. */
class JobRatingTest extends TestCase
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
    private function putJobIn(int $jobId, string $status, bool $withPlumber = true): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get($jobId);
        $job->set('status', $status);
        $jobs->saveOrFail($job);

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

    private function ratingOf(int $jobId): ?int
    {
        $rating = TableRegistry::getTableLocator()->get('JobRequests')->get($jobId)->customer_rating;

        return $rating === null ? null : (int)$rating;
    }

    public function testCustomerCanRateTheirCompletedJob(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED);
        $this->signInAsCustomer();
        $this->enableCsrfToken();

        $this->post('/job-requests/rate/1', ['rating' => '4']);

        $this->assertRedirect('/job-requests/confirmation/1');
        $this->assertFlashMessage('Thank you. Your rating has been saved.');
        $this->assertSame(4, $this->ratingOf(1));
        $this->assertNotNull(TableRegistry::getTableLocator()->get('JobRequests')->get(1)->rated_at);
    }

    public function testASecondRatingIsRefusedAndTheFirstIsKept(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED);
        $this->signInAsCustomer();
        $this->enableCsrfToken();

        $this->post('/job-requests/rate/1', ['rating' => '5']);
        $this->post('/job-requests/rate/1', ['rating' => '1']);

        $this->assertRedirect('/job-requests/confirmation/1');
        $this->assertFlashMessage('You have already rated this job.');
        $this->assertSame(5, $this->ratingOf(1));
    }

    public function testAJobThatIsNotCompletedCannotBeRated(): void
    {
        $this->signInAsCustomer();
        $this->enableCsrfToken();

        foreach (
            [
                JobRequest::STATUS_SUBMITTED,
                JobRequest::STATUS_REVIEWING,
                JobRequest::STATUS_APPROVED,
                JobRequest::STATUS_ASSIGNED,
                JobRequest::STATUS_IN_PROGRESS,
                JobRequest::STATUS_UNABLE_TO_COMPLETE,
            ] as $status
        ) {
            $this->putJobIn(1, $status);
            $this->post('/job-requests/rate/1', ['rating' => '5']);

            $this->assertRedirect('/job-requests/confirmation/1');
            $this->assertFlashMessage('You can only rate a job once it has been completed.');
            $this->assertNull($this->ratingOf(1), $status);
        }
    }

    public function testACompletedJobWithoutAPlumberCannotBeRated(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED, false);
        $this->signInAsCustomer();
        $this->enableCsrfToken();

        $this->post('/job-requests/rate/1', ['rating' => '5']);

        $this->assertFlashMessage('You can only rate a job once it has been completed.');
        $this->assertNull($this->ratingOf(1));
    }

    public function testACustomerCannotRateAnotherCustomersJob(): void
    {
        $this->putJobIn(2, JobRequest::STATUS_COMPLETED);
        $this->signInAsCustomer(1);
        $this->enableCsrfToken();

        Configure::write('debug', false);
        $this->post('/job-requests/rate/2', ['rating' => '5']);

        $this->assertResponseCode(404);
        $this->assertNull($this->ratingOf(2));
    }

    public function testTamperedRatingValuesAreRejectedAndNothingIsSaved(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED);
        $this->signInAsCustomer();
        $this->enableCsrfToken();

        $values = ['0', '6', '-1', '2.5', '3.0', '03', ' 4', 'five', '', '１', ['3'], ['rating' => '3']];
        foreach ($values as $value) {
            $this->post('/job-requests/rate/1', ['rating' => $value]);

            $this->assertRedirect('/job-requests/confirmation/1');
            $this->assertFlashMessage('Please choose a rating from 1 to 5 stars.');
            $this->assertNull($this->ratingOf(1), var_export($value, true));
        }

        $this->post('/job-requests/rate/1', []);
        $this->assertFlashMessage('Please choose a rating from 1 to 5 stars.');
        $this->assertNull($this->ratingOf(1));
    }

    public function testNoRatingCanBeSavedWithoutACsrfToken(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED);
        $this->signInAsCustomer();

        $this->post('/job-requests/rate/1', ['rating' => '5']);

        $this->assertResponseCode(403);
        $this->assertNull($this->ratingOf(1));
    }

    public function testLoggedOutVisitorsAndOfficeAccountsCannotRate(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED);
        $this->enableCsrfToken();

        $this->post('/job-requests/rate/1', ['rating' => '5']);
        $this->assertRedirectContains('/login');
        $this->assertNull($this->ratingOf(1));

        foreach ([[1, 'hannah', 'admin'], [2, 'staffuser', 'staff'], [3, 'manageruser', 'manager']] as [$id, $name, $role]) {
            $this->session(['AdminAuth' => ['admin_id' => $id, 'admin_username' => $name, 'role' => $role]]);
            $this->post('/job-requests/rate/1', ['rating' => '5']);

            $this->assertRedirectContains('/login');
            $this->assertNull($this->ratingOf(1), $role);
        }
    }

    public function testRatingCannotBeSavedWithAGetRequest(): void
    {
        $this->putJobIn(1, JobRequest::STATUS_COMPLETED);
        $this->signInAsCustomer();

        Configure::write('debug', false);
        $this->get('/job-requests/rate/1?rating=5');

        $this->assertContains($this->_response->getStatusCode(), [404, 405]);
        $this->assertNull($this->ratingOf(1));
    }
}
