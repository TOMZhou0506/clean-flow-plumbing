<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** Customers' ratings of each plumber are for the office (the manager and admins), and nobody else. */
class PlumbersRatingColumnTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
    ];

    private int $nextJobId = 100;

    private function signIn(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id,
            'admin_username' => match ($id) {
                1 => 'hannah',
                2 => 'staffuser',
                default => 'manageruser',
            },
            'role' => $role,
        ]]);
    }

    /** A second plumber, so one can have ratings and the other none. */
    private function addSecondPlumber(): void
    {
        TableRegistry::getTableLocator()->get('Admins')->getConnection()->insert('admins', [
            'admin_id' => 4,
            'admin_username' => 'otherplumber',
            'admin_email' => 'otherplumber@example.com',
            'admin_password' => password_hash('Whatever#2026', PASSWORD_DEFAULT),
            'role' => 'staff',
            'active' => 1,
        ]);
    }

    private function addCompletedJob(int $plumberId, ?int $rating): void
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
            'status' => 'completed',
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

    public function testTheManagerSeesEachPlumbersAverageAndCount(): void
    {
        $this->addSecondPlumber();
        foreach ([5, 4, 4] as $rating) {
            $this->addCompletedJob(2, $rating);
        }
        $this->addCompletedJob(4, null);
        $this->signIn(3, 'manager');

        $this->get('/plumbers');

        $this->assertResponseOk();
        $this->assertResponseContains('Customer rating');
        $this->assertResponseContains('4.3 / 5');
        $this->assertResponseContains('(3 ratings)');
        $this->assertResponseContains('No ratings yet');
    }

    public function testASingleRatingIsWrittenInTheSingular(): void
    {
        $this->addCompletedJob(2, 2);
        $this->signIn(3, 'manager');

        $this->get('/plumbers');

        $this->assertResponseContains('2.0 / 5');
        $this->assertResponseContains('(1 rating)');
        $this->assertResponseNotContains('(1 ratings)');
    }

    public function testAPlumberWithNoRatingsAtAllShowsNoRatingsYet(): void
    {
        $this->signIn(3, 'manager');

        $this->get('/plumbers');

        $this->assertResponseContains('No ratings yet');
        $this->assertResponseNotContains('/ 5');
    }

    public function testADeactivatedPlumbersRatingsAreStillShown(): void
    {
        $this->addCompletedJob(2, 5);
        $accounts = TableRegistry::getTableLocator()->get('Admins');
        $plumber = $accounts->get(2);
        $plumber->set('active', false);
        $accounts->saveOrFail($plumber);
        $this->signIn(3, 'manager');

        $this->get('/plumbers');

        $this->assertResponseContains('Deactivated');
        $this->assertResponseContains('5.0 / 5');
    }

    public function testAdminsSeeEachPlumbersRatingToo(): void
    {
        foreach ([5, 4, 4] as $rating) {
            $this->addCompletedJob(2, $rating);
        }
        $this->signIn(1, 'admin');

        $this->get('/plumbers');

        $this->assertResponseOk();
        $this->assertResponseContains('staffuser');
        $this->assertResponseContains('Customer rating');
        $this->assertResponseContains('4.3 / 5');
        $this->assertResponseContains('(3 ratings)');

        // The availability board shows the same average beside the plumber's name.
        $this->get('/plumber-availability');
        $this->assertResponseOk();
        $this->assertResponseContains('★ 4.3');
    }

    public function testTheAdminJobPageShowsTheCustomersRating(): void
    {
        $this->addCompletedJob(2, 4);
        $this->signIn(1, 'admin');

        $this->get('/job-requests/manage/100');

        $this->assertResponseOk();
        $this->assertResponseContains('Customer rating');
        $this->assertResponseContains('★★★★☆');
        $this->assertResponseContains('4 out of 5');
    }

    public function testPlumbersAndSignedOutVisitorsGetNoRatingsEither(): void
    {
        $this->addCompletedJob(2, 5);
        Configure::write('debug', false);

        $this->get('/plumbers');
        $this->assertRedirectContains('/login');
        $this->assertResponseNotContains('5.0');

        $this->signIn(2, 'staff');
        $this->get('/plumbers');
        $this->assertResponseCode(403);
        $this->assertResponseNotContains('5.0');
        $this->assertResponseNotContains('Customer rating');
    }
}
