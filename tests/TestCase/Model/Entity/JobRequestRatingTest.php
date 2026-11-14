<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\JobRequest;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/** A customer can rate a completed job once, and only when a plumber did the work. */
class JobRequestRatingTest extends TestCase
{
    protected array $fixtures = ['app.Customers', 'app.JobRequests'];

    private function job(string $status, ?int $rating, bool $assigned): JobRequest
    {
        return new JobRequest([
            'id' => 7,
            'status' => $status,
            'customer_rating' => $rating,
            'job_assignment' => $assigned ? new Entity(['id' => 1]) : null,
        ]);
    }

    public function testOnlyACompletedUnratedJobWithAPlumberCanBeRated(): void
    {
        $this->assertTrue($this->job(JobRequest::STATUS_COMPLETED, null, true)->canBeRated());

        $this->assertFalse($this->job(JobRequest::STATUS_COMPLETED, 4, true)->canBeRated());
        $this->assertFalse($this->job(JobRequest::STATUS_COMPLETED, null, false)->canBeRated());
        foreach (
            [
                JobRequest::STATUS_SUBMITTED,
                JobRequest::STATUS_REVIEWING,
                JobRequest::STATUS_APPROVED,
                JobRequest::STATUS_DECLINED,
                JobRequest::STATUS_ASSIGNED,
                JobRequest::STATUS_IN_PROGRESS,
                JobRequest::STATUS_UNABLE_TO_COMPLETE,
                JobRequest::STATUS_ARCHIVED,
            ] as $status
        ) {
            $this->assertFalse($this->job($status, null, true)->canBeRated(), $status);
        }
    }

    public function testRatingIsDrawnAsStars(): void
    {
        $this->assertSame('', $this->job(JobRequest::STATUS_COMPLETED, null, true)->ratingStars());
        $this->assertSame('★☆☆☆☆', $this->job(JobRequest::STATUS_COMPLETED, 1, true)->ratingStars());
        $this->assertSame('★★★★☆', $this->job(JobRequest::STATUS_COMPLETED, 4, true)->ratingStars());
        $this->assertSame('★★★★★', $this->job(JobRequest::STATUS_COMPLETED, 5, true)->ratingStars());
    }

    public function testRatingColumnsCannotBeMassAssigned(): void
    {
        $job = TableRegistry::getTableLocator()->get('JobRequests')
            ->newEntity(['customer_rating' => 5, 'rated_at' => '2030-01-01 00:00:00']);

        $this->assertNull($job->customer_rating);
        $this->assertNull($job->rated_at);
    }
}
