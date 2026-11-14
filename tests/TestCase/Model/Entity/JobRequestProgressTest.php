<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\JobRequest;
use Cake\TestSuite\TestCase;

/**
 * The stages the progress bar draws for each status.
 */
class JobRequestProgressTest extends TestCase
{
    /**
     * @param string $status A JobRequest status.
     * @return array<string, string> Step key => state.
     */
    private function states(string $status): array
    {
        $job = new JobRequest(['status' => $status]);
        $result = [];
        foreach ($job->progressSteps() as $step) {
            $result[$step['key']] = $step['state'];
        }

        return $result;
    }

    public function testASubmittedRequestIsAtTheComplianceStage(): void
    {
        $this->assertSame([
            'received' => 'done',
            'compliance' => 'current',
            'assign' => 'upcoming',
            'approval' => 'upcoming',
            'work' => 'upcoming',
            'done' => 'upcoming',
        ], $this->states(JobRequest::STATUS_SUBMITTED));
    }

    public function testEachStatusMovesTheCurrentStageOneAlong(): void
    {
        $this->assertSame('current', $this->states(JobRequest::STATUS_REVIEWING)['compliance']);
        $this->assertSame('current', $this->states(JobRequest::STATUS_APPROVED)['assign']);
        $this->assertSame('current', $this->states(JobRequest::STATUS_ASSIGNED)['approval']);
        $this->assertSame('current', $this->states(JobRequest::STATUS_IN_PROGRESS)['work']);

        $approved = $this->states(JobRequest::STATUS_APPROVED);
        $this->assertSame('done', $approved['compliance']);
    }

    public function testACompletedJobHasEveryStageDone(): void
    {
        $this->assertSame(
            ['done'],
            array_values(array_unique($this->states(JobRequest::STATUS_COMPLETED))),
        );
    }

    public function testDeclinedAndUnableToCompleteStopAtTheStageTheyReached(): void
    {
        $declined = $this->states(JobRequest::STATUS_DECLINED);
        $this->assertSame('stopped', $declined['compliance']);
        $this->assertSame('upcoming', $declined['assign']);

        $unable = $this->states(JobRequest::STATUS_UNABLE_TO_COMPLETE);
        $this->assertSame('stopped', $unable['done']);
        $this->assertSame('done', $unable['work']);
    }

    public function testThePlumberSeesPendingApprovalWhileTheOfficeSeesPlumberApproval(): void
    {
        $assigned = new JobRequest(['status' => JobRequest::STATUS_ASSIGNED]);

        $this->assertSame('Pending approval', $assigned->plumberStatusLabel());
        $this->assertSame('Pending plumber approval', $assigned->officeStatusLabel());

        // Every other status reads the same on both sides.
        $working = new JobRequest(['status' => JobRequest::STATUS_IN_PROGRESS]);
        $this->assertSame($working->officeStatusLabel(), $working->plumberStatusLabel());
    }

    public function testCustomerStepsUseCustomerWordingAndTheSameStates(): void
    {
        $job = new JobRequest(['status' => JobRequest::STATUS_ASSIGNED]);

        $office = $job->progressSteps();
        $customer = $job->progressSteps(true);

        $this->assertSame(array_column($office, 'state'), array_column($customer, 'state'));
        $this->assertSame(
            ['Request received', 'Under review', 'Arranging your visit', 'Appointment scheduled', 'Work in progress', 'Completed'],
            array_column($customer, 'label'),
        );
    }

    public function testACustomerWhoJustSubmittedIsAtRequestReceivedNotUnderReview(): void
    {
        $job = new JobRequest(['status' => JobRequest::STATUS_SUBMITTED]);

        $states = [];
        foreach ($job->progressSteps(true) as $step) {
            $states[$step['label']] = $step['state'];
        }
        $this->assertSame('current', $states['Request received']);
        $this->assertSame('upcoming', $states['Under review']);

        // The office still counts the request as received and sits on the check.
        $office = [];
        foreach ($job->progressSteps() as $step) {
            $office[$step['key']] = $step['state'];
        }
        $this->assertSame('done', $office['received']);
        $this->assertSame('current', $office['compliance']);

        // Once somebody opens it, it is under review.
        $reviewing = new JobRequest(['status' => JobRequest::STATUS_REVIEWING]);
        $this->assertSame('current', $reviewing->progressSteps(true)[1]['state']);
        $this->assertSame('done', $reviewing->progressSteps(true)[0]['state']);
    }

    public function testACustomerSeesTheirOwnLabelWhenARequestStops(): void
    {
        $declined = (new JobRequest(['status' => JobRequest::STATUS_DECLINED]))->progressSteps(true);

        $this->assertSame('Unable to accept', $declined[1]['label']);
        $this->assertSame('stopped', $declined[1]['state']);
    }

    public function testOfficeStatusLabelsUseTheWorkflowWording(): void
    {
        $this->assertSame(
            'Pending plumber approval',
            (new JobRequest(['status' => JobRequest::STATUS_ASSIGNED]))->officeStatusLabel(),
        );
        $this->assertSame(
            'Compliance check',
            (new JobRequest(['status' => JobRequest::STATUS_REVIEWING]))->officeStatusLabel(),
        );
    }
}
