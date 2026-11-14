<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/** Admin-only, read-only overview with durable per-admin notifications. */
class PlumberAvailabilityNotificationsTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.PlumberUnavailabilities',
        'app.AvailabilityAlertDismissals',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
    ];

    private function signIn(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id,
            'admin_username' => $id === 2 ? 'staffuser' : 'hannah',
            'role' => $role,
        ]]);
    }

    public function testAddedAndWithdrawnPeriodsNotifyAdminAndDismissalPersists(): void
    {
        $date = (new DateTimeImmutable('next monday', new DateTimeZone('Australia/Melbourne')))->format('Y-m-d');
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $this->post('/plumbers/availability', [
            'unavailable_date' => $date,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
        $this->assertRedirect();
        $period = TableRegistry::getTableLocator()->get('PlumberUnavailabilities')->find()->firstOrFail();

        $this->signIn(1, 'admin');
        $this->get('/job-requests/manage');
        $this->assertResponseOk();
        $this->assertResponseContains('new plumber availability update');
        $this->assertResponseContains('period-' . $period->id);
        $this->get('/plumber-availability');
        $this->assertResponseOk();
        $this->assertResponseContains('staffuser');
        $this->assertResponseContains('09:00–12:00');
        $this->assertResponseContains('period-' . $period->id);
        $this->assertResponseNotContains('Approve');

        $event = TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'plumber.unavailable_added'])->firstOrFail();
        $this->enableCsrfToken();
        $this->post('/plumber-availability/dismiss/' . $event->id);
        $this->assertRedirect();
        $this->get('/plumber-availability');
        $this->assertResponseOk();
        $this->assertResponseContains('No new availability updates.');
        $this->assertResponseContains('09:00–12:00');

        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $this->post('/plumbers/cancel-unavailable/' . $period->id);
        $this->assertRedirect();
        $this->signIn(1, 'admin');
        $this->get('/plumber-availability');
        $this->assertResponseOk();
        $this->assertResponseContains('unavailable period withdrawn');
        $this->assertResponseNotContains('now unavailable');
    }

    public function testOnlyAdminsCanReadOrDismiss(): void
    {
        Configure::write('debug', false);
        foreach ([[2, 'staff'], [3, 'manager']] as [$id, $role]) {
            $this->signIn($id, $role);
            $this->get('/plumber-availability');
            $this->assertResponseCode(403);
            $this->enableCsrfToken();
            $this->post('/plumber-availability/dismiss/1');
            $this->assertResponseCode(403);
        }
    }
}
