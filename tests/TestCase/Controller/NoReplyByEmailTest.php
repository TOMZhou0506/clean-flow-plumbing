<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Staff, admins and the manager reply from their own mailbox.
 *
 * The "Reply by email" button and the customer email links were mailto: links,
 * which only worked on a machine with a mail program set up. They are gone from
 * every office page, and nothing replaces them.
 */
class NoReplyByEmailTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.ComplianceChecks',
        'app.ContactSubmissions',
        'app.Customers',
        'app.JobRequests',
    ];

    /**
     * @return void
     */
    public function testNoOfficePageOffersAReplyByEmailLink(): void
    {
        // Only admins can open these pages. Plumbers and the manager are refused,
        // so there is nothing to render for them.
        $this->session(['AdminAuth' => ['admin_id' => 1, 'admin_username' => 'hannah', 'role' => 'admin']]);

        $pages = [
            '/contact-submissions',
            '/contact-submissions/view/1',
            '/job-requests/manage',
            '/job-requests/manage-view/1',
        ];
        foreach ($pages as $page) {
            $this->get($page);

            $this->assertResponseOk($page);
            $this->assertResponseNotContains('Reply by email', $page);
            $this->assertResponseNotContains('mailto:', $page);
        }
    }

    /**
     * The address is still shown, as plain text, so it can be read and copied.
     *
     * @return void
     */
    public function testCustomerEmailsAreStillShownAsPlainText(): void
    {
        $this->session(['AdminAuth' => ['admin_id' => 1, 'admin_username' => 'hannah', 'role' => 'admin']]);

        $this->get('/job-requests/manage');
        $this->assertResponseOk();
        $this->assertResponseContains('kash@example.com');

        $this->get('/contact-submissions');
        $this->assertResponseOk();
        $this->assertResponseRegExp('/<td>[^<]+@[^<]+<\/td>/');
    }
}
