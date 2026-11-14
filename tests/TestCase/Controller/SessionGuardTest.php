<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The closed-site sign out (webroot/js/session-guard.js) is on every signed-in
 * page with the right logout route, never on a signed-out page, and the sign-in
 * form refreshes it so a fresh sign-in is not undone.
 */
class SessionGuardTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.Customers',
        'app.JobRequests',
        'app.SiteContents',
    ];

    public function testOfficePagesCarryTheGuardWithTheOfficeLogout(): void
    {
        $this->session(['AdminAuth' => ['admin_id' => 1, 'admin_username' => 'hannah', 'role' => 'admin']]);

        foreach (['/job-requests/manage', '/'] as $url) {
            $this->get($url);
            $this->assertResponseOk($url);
            $this->assertResponseContains('data-session-guard');
            $this->assertResponseContains('data-logout-url="/admins/logout"');
            $this->assertResponseContains('/js/session-guard.js');
        }
    }

    public function testCustomerPagesCarryTheGuardWithTheCustomerLogout(): void
    {
        $this->session(['CustomerAuth' => [
            'cust_id' => 1,
            'cust_email' => 'kash@example.com',
            'cust_fname' => 'Kash',
            'cust_lname' => 'Patel',
        ]]);

        foreach (['/job-requests', '/'] as $url) {
            $this->get($url);
            $this->assertResponseOk($url);
            $this->assertResponseContains('data-session-guard');
            $this->assertResponseContains('data-logout-url="/customers/logout"');
        }
    }

    public function testSignedOutPagesHaveNoGuardAndTheSignInFormRefreshesIt(): void
    {
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseNotContains('data-session-guard');

        $this->get('/login');
        $this->assertResponseOk();
        $this->assertResponseNotContains('data-session-guard');
        $this->assertResponseContains('data-session-start');
        $this->assertResponseContains('/js/session-guard.js');
    }
}
