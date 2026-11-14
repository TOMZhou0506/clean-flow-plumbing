<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * The development-only demo data command: it fills a plumber's dashboard,
 * refuses to run twice, can be undone, and never runs with debug off.
 */
class SeedDemoJobsCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins', 'app.Customers', 'app.JobRequests', 'app.JobAssignments',
        'app.ComplianceChecks', 'app.Vehicles',
    ];

    /**
     * @var bool
     */
    private bool $debugWas;

    /**
     * This command refuses to run unless debug is on, which is the right guard
     * for something that writes demo data. It does mean these tests have to
     * turn debug on deliberately rather than inheriting whatever the machine
     * happens to have: without this the class passes for a developer who has
     * DEBUG set in their environment and fails for one who does not, which is
     * exactly what happened.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->debugWas = (bool)Configure::read('debug');
        Configure::write('debug', true);
    }

    /**
     * Puts debug back. testItRefusesToRunWithDebugOff turns it off and would
     * otherwise leak that into whatever test class runs next.
     *
     * @return void
     */
    public function tearDown(): void
    {
        Configure::write('debug', $this->debugWas);

        parent::tearDown();
    }

    private function demoJobs(): int
    {
        return TableRegistry::getTableLocator()->get('JobAssignments')->find()
            ->innerJoinWith('JobRequests.Customers')
            ->where(['Customers.cust_email LIKE' => 'demo.customer%@example.com'])
            ->count();
    }

    public function testItGivesThePlumberAFullMixOfJobs(): void
    {
        $this->exec('seed_demo_jobs --plumber staffuser');

        $this->assertExitSuccess();
        $this->assertOutputContains('Created 9 demo jobs');
        $this->assertSame(9, $this->demoJobs());

        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $mine = $assignments->find()->contain(['JobRequests'])
            ->innerJoinWith('JobRequests.Customers')
            ->where([
                'Customers.cust_email LIKE' => 'demo.customer%@example.com',
                'JobAssignments.plumber_admin_id' => 2,
            ])->all();

        $statuses = [];
        foreach ($mine as $assignment) {
            $statuses[] = $assignment->job_request->status;
        }
        sort($statuses);
        $this->assertSame(
            ['assigned', 'assigned', 'assigned', 'assigned', 'completed', 'completed', 'completed',
                'in_progress', 'unable_to_complete'],
            $statuses,
        );

        // Assigned and later jobs have a fully ticked compliance checklist.
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks')->find()
            ->where(['subject_type' => 'job_request', 'is_met' => true])
            ->count();
        $this->assertGreaterThanOrEqual(36, $checks);
    }

    public function testItRefusesToRunTwiceAndRemoveUndoesIt(): void
    {
        $this->exec('seed_demo_jobs --plumber staffuser');
        $this->assertExitSuccess();

        $this->exec('seed_demo_jobs --plumber staffuser');
        $this->assertExitError();
        $this->assertErrorContains('already there');
        $this->assertSame(9, $this->demoJobs());

        $this->exec('seed_demo_jobs --remove');
        $this->assertExitSuccess();
        $this->assertOutputContains('Removed 9 demo jobs');
        $this->assertSame(0, $this->demoJobs());

        // Real data was not touched: the fixture's own job requests remain.
        $this->assertSame(
            2,
            TableRegistry::getTableLocator()->get('JobRequests')->find()->count(),
        );
    }

    public function testItRefusesToRunWithDebugOff(): void
    {
        Configure::write('debug', false);

        $this->exec('seed_demo_jobs --plumber staffuser');

        $this->assertExitError();
        $this->assertErrorContains('debug off');
        $this->assertSame(0, $this->demoJobs());
    }

    public function testItReportsAnUnknownPlumber(): void
    {
        $this->exec('seed_demo_jobs --plumber nobody-by-that-name');

        $this->assertExitError();
        $this->assertErrorContains('No active plumber');
    }
}
