<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\ContactSubmission;
use App\Model\Exception\ComplianceNotMetException;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/**
 * US 3.3: a job which has not passed compliance cannot proceed, and jobs can
 * be found by compliance status.
 *
 * These are model level tests rather than controller ones on purpose. The
 * compliance gate lives on the table, so that a console command or a
 * teammate's new action cannot walk past it, and this is where that claim is
 * worth proving. US 3.4 covers the same ground through the UI.
 */
class JobStageAndComplianceFilterTest extends TestCase
{
    protected array $fixtures = [
        'app.Admins',
        'app.ContactSubmissions',
        'app.ComplianceChecks',
        'app.AuditLogs',
    ];

    /**
     * @var \App\Model\Table\ContactSubmissionsTable
     */
    protected $submissions;

    /**
     * @var \App\Model\Table\ComplianceChecksTable
     */
    protected $checks;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');
        $this->checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
    }

    /**
     * Marks every requirement on an enquiry as met.
     *
     * @param int $submissionId The enquiry.
     * @return void
     */
    protected function makeCompliant(int $submissionId): void
    {
        foreach (array_keys(ComplianceCheck::REQUIREMENTS) as $requirement) {
            $this->checks->record($submissionId, $requirement, true, null, 1);
        }
    }

    // -----------------------------------------------------------------------
    // A job which has not passed compliance cannot proceed
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testEveryEnquiryStartsAtTheEnquiryStage(): void
    {
        $this->assertSame(
            ContactSubmission::STAGE_ENQUIRY,
            $this->submissions->get(1)->job_stage,
        );
    }

    /**
     * Submission 1 has one requirement met out of four, so it is not clear.
     *
     * @return void
     */
    public function testAPartlyCompliantJobCannotAdvance(): void
    {
        $this->expectException(ComplianceNotMetException::class);

        $this->submissions->advanceStage(1, $this->checks);
    }

    /**
     * An enquiry nobody has reviewed must be refused too. Treating "nobody
     * checked" as "cleared" would be the worst possible failure here.
     *
     * @return void
     */
    public function testAnUnreviewedJobCannotAdvance(): void
    {
        $this->expectException(ComplianceNotMetException::class);

        $this->submissions->advanceStage(3, $this->checks);
    }

    /**
     * The refusal says why, and names the numbers. "You cannot do that"
     * without a reason is what makes people work around a system.
     *
     * @return void
     */
    public function testTheRefusalExplainsWhatIsOutstanding(): void
    {
        try {
            $this->submissions->advanceStage(1, $this->checks);
            $this->fail('Expected the advance to be refused.');
        } catch (ComplianceNotMetException $e) {
            $this->assertStringContainsString('cannot proceed', $e->getMessage());
            $this->assertStringContainsString('Approved', $e->getMessage());
            $this->assertStringContainsString('1 of 4', $e->getMessage());
            $this->assertStringContainsString('3 still outstanding', $e->getMessage());
        }
    }

    /**
     * A refused advance must leave the stage where it was. A partial change
     * would be worse than a refusal, because the job would look progressed.
     *
     * @return void
     */
    public function testARefusedAdvanceLeavesTheStageUnchanged(): void
    {
        try {
            $this->submissions->advanceStage(1, $this->checks);
        } catch (ComplianceNotMetException) {
            // Expected.
        }

        $this->assertSame(
            ContactSubmission::STAGE_ENQUIRY,
            $this->submissions->get(1)->job_stage,
        );
    }

    /**
     * @return void
     */
    public function testAFullyCompliantJobCanAdvance(): void
    {
        $this->makeCompliant(1);

        $submission = $this->submissions->advanceStage(1, $this->checks);

        $this->assertSame(ContactSubmission::STAGE_APPROVED, $submission->job_stage);
        $this->assertSame(
            ContactSubmission::STAGE_APPROVED,
            $this->submissions->get(1)->job_stage,
        );
    }

    /**
     * Only the entry to the workflow is gated. Once a job is approved,
     * compliance has already been confirmed, and re-checking at every step
     * would let an unrelated unticked box freeze a job halfway through.
     *
     * @return void
     */
    public function testLaterStagesAreNotGatedByCompliance(): void
    {
        $this->makeCompliant(1);
        $this->submissions->advanceStage(1, $this->checks);

        // Un-tick a requirement now that the job is approved.
        $this->checks->record(1, 'licence', false, 'Licence expired.', 1);

        $submission = $this->submissions->advanceStage(1, $this->checks);
        $this->assertSame(ContactSubmission::STAGE_SCHEDULED, $submission->job_stage);
    }

    /**
     * @return void
     */
    public function testAJobAtTheFinalStageCannotAdvanceFurther(): void
    {
        $this->makeCompliant(1);
        $this->submissions->advanceStage(1, $this->checks);
        $this->submissions->advanceStage(1, $this->checks);
        $this->submissions->advanceStage(1, $this->checks);

        $this->assertSame(
            ContactSubmission::STAGE_COMPLETED,
            $this->submissions->get(1)->job_stage,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->submissions->advanceStage(1, $this->checks);
    }

    /**
     * The gate cannot be bypassed by patching the entity from request data.
     *
     * This is the one that actually matters. A guard the caller can route
     * around is decoration, so job_stage is not mass assignable and this
     * proves it.
     *
     * @return void
     */
    public function testJobStageCannotBeSetThroughMassAssignment(): void
    {
        $submission = $this->submissions->get(1);

        $this->submissions->patchEntity($submission, [
            'job_stage' => ContactSubmission::STAGE_COMPLETED,
        ]);
        $this->submissions->save($submission);

        $this->assertSame(
            ContactSubmission::STAGE_ENQUIRY,
            $this->submissions->get(1)->job_stage,
            'A request body was able to set the job stage directly.',
        );
    }

    // -----------------------------------------------------------------------
    // Find jobs by compliance status
    // -----------------------------------------------------------------------

    /**
     * Pulls the ids a filter returns.
     *
     * @param array<string, string> $filters The filters to apply.
     * @return list<int>
     */
    protected function idsFor(array $filters): array
    {
        $ids = $this->submissions->buildListQuery($filters)
            ->all()
            ->extract('id')
            ->toList();

        sort($ids);

        return array_map('intval', $ids);
    }

    /**
     * @return void
     */
    public function testFilteringByFullyMetReturnsOnlyClearedJobs(): void
    {
        $this->makeCompliant(2);

        $this->assertSame(
            [2],
            $this->idsFor(['compliance' => ComplianceCheck::PROGRESS_MET]),
        );
    }

    /**
     * Submission 1 has one of four met. Submission 2 has one of four in the
     * fixture, so I clear submission 2 first to keep the two states apart.
     *
     * @return void
     */
    public function testFilteringByPartialReturnsOnlyPartlyMetJobs(): void
    {
        $this->makeCompliant(2);

        $this->assertSame(
            [1],
            $this->idsFor(['compliance' => ComplianceCheck::PROGRESS_PARTIAL]),
        );
    }

    /**
     * Pending covers both "reviewed and nothing met" and "never reviewed",
     * because both need the same follow-up.
     *
     * @return void
     */
    public function testFilteringByPendingReturnsUnreviewedJobs(): void
    {
        $pending = $this->idsFor(['compliance' => ComplianceCheck::PROGRESS_PENDING]);

        // Submissions 1 and 2 each have a met requirement in the fixture, so
        // everything else is pending.
        $this->assertNotContains(1, $pending);
        $this->assertNotContains(2, $pending);
        $this->assertContains(3, $pending);
    }

    /**
     * The three states must partition the list. If they overlap or leave a
     * gap, an enquiry is either double counted or invisible, and an invisible
     * enquiry is a customer nobody replies to.
     *
     * @return void
     */
    public function testTheThreeComplianceStatesCoverEveryEnquiryExactlyOnce(): void
    {
        $this->makeCompliant(2);

        $all = $this->idsFor([]);
        $met = $this->idsFor(['compliance' => ComplianceCheck::PROGRESS_MET]);
        $partial = $this->idsFor(['compliance' => ComplianceCheck::PROGRESS_PARTIAL]);
        $pending = $this->idsFor(['compliance' => ComplianceCheck::PROGRESS_PENDING]);

        $combined = array_merge($met, $partial, $pending);
        sort($combined);

        $this->assertSame($all, $combined, 'The compliance states overlap or miss enquiries.');
    }

    /**
     * A crafted value narrows nothing rather than erroring or, worse,
     * returning an empty list that looks like a real answer.
     *
     * @return void
     */
    public function testAnUnrecognisedComplianceFilterIsIgnored(): void
    {
        $this->assertSame(
            $this->idsFor([]),
            $this->idsFor(['compliance' => "'; DROP TABLE compliance_checks; --"]),
        );
    }

    /**
     * @return void
     */
    public function testFilteringByStageReturnsOnlyThatStage(): void
    {
        $this->makeCompliant(1);
        $this->submissions->advanceStage(1, $this->checks);

        $this->assertSame(
            [1],
            $this->idsFor(['stage' => ContactSubmission::STAGE_APPROVED]),
        );
        $this->assertNotContains(
            1,
            $this->idsFor(['stage' => ContactSubmission::STAGE_ENQUIRY]),
        );
    }

    /**
     * @return void
     */
    public function testAnUnrecognisedStageFilterIsIgnored(): void
    {
        $this->assertSame(
            $this->idsFor([]),
            $this->idsFor(['stage' => 'not-a-stage']),
        );
    }

    /**
     * Compliance and stage filters combine rather than one overriding the
     * other.
     *
     * @return void
     */
    public function testComplianceAndStageFiltersCombine(): void
    {
        $this->makeCompliant(1);
        $this->submissions->advanceStage(1, $this->checks);
        $this->makeCompliant(2);

        // Both are fully met, but only one is approved.
        $this->assertSame(
            [1],
            $this->idsFor([
                'compliance' => ComplianceCheck::PROGRESS_MET,
                'stage' => ContactSubmission::STAGE_APPROVED,
            ]),
        );
    }
}
