<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\ComplianceCheck;
use Cake\ORM\TableRegistry;

/**
 * Ticks every compliance requirement on a job request.
 *
 * A job request cannot be approved until its compliance checklist is complete,
 * so a test that needs an approved request has to tick the list first, the
 * same as an admin would.
 */
trait TicksComplianceTrait
{
    /**
     * @param int $jobId The job request to clear.
     * @param int $adminId Who ticked it.
     * @return void
     */
    protected function tickAllCompliance(int $jobId, int $adminId = 1): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        foreach (array_keys(ComplianceCheck::REQUIREMENTS) as $requirement) {
            $checks->record(
                $jobId,
                $requirement,
                true,
                null,
                $adminId,
                ComplianceCheck::SUBJECT_JOB_REQUEST,
            );
        }
    }
}
