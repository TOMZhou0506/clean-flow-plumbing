<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Long values and tight spacing in the office and customer pages.
 *
 * The overflow itself is a matter of CSS, which these tests cannot render. They check that
 * the markup and the rules that keep the layout in place are there, so a later edit cannot
 * remove them without a test failing. The visual check was done in a real browser at
 * 1366, 768, 375 and 320 pixels wide.
 */
class LongValuesLayoutTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.ComplianceChecks',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
    ];

    private function css(string $file): string
    {
        return (string)file_get_contents(WWW_ROOT . 'css' . DS . $file);
    }

    /**
     * The declarations inside the first rule that starts with this selector.
     */
    private function rule(string $css, string $selector): string
    {
        $this->assertSame(
            1,
            preg_match('/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $match),
            $selector . ' rule not found',
        );

        return $match[1];
    }

    private function signInAsAdmin(): void
    {
        $this->session(['AdminAuth' => ['admin_id' => 1, 'admin_username' => 'hannah', 'role' => 'admin']]);
    }

    public function testLongUnbrokenTextWrapsEverywhereInTheOfficePages(): void
    {
        $dashboard = $this->css('dashboard.css');

        $this->assertStringContainsString('overflow-wrap: anywhere', $this->rule($dashboard, '.dash'));
        $this->assertStringContainsString('overflow-wrap: anywhere', $this->rule($this->css('cake.css'), '.message'));
        $this->assertStringContainsString('min-width: 0', $this->rule($dashboard, '.dash__header'));
    }

    public function testTheButtonsInAPageHeaderWrapOnNarrowPhones(): void
    {
        $this->assertStringContainsString(
            'flex-wrap: wrap',
            $this->rule($this->css('dashboard.css'), '.dash__header-actions'),
        );
    }

    public function testTheContactCheckBoxScrollsInsideItsOwnBox(): void
    {
        $this->assertStringContainsString('overflow-x: auto', $this->rule($this->css('cake.css'), '.cf-turnstile'));
    }

    public function testStatusBadgesWrapInsteadOfSpillingOutOfNarrowColumns(): void
    {
        $badge = $this->rule($this->css('dashboard.css'), '.dash__badge');

        $this->assertStringContainsString('white-space: normal', $badge);
        $this->assertStringNotContainsString('nowrap', $badge);
        $this->assertStringContainsString('max-width: 100%', $badge);
    }

    public function testTheCustomerNameInTheHeaderIsCutShortInsteadOfWideningThePage(): void
    {
        $label = $this->css('cake.css');

        $this->assertMatchesRegularExpression(
            '/\.top-nav-links \.customer-menu__label\s*\{[^}]*max-width:\s*16rem[^}]*text-overflow:\s*ellipsis/s',
            $label,
        );
    }

    public function testTablesAndTheBlocksAboveThemUseTheTighterSpacing(): void
    {
        $dashboard = $this->css('dashboard.css');

        $this->assertStringContainsString('padding: 9px 12px', $this->rule($dashboard, '.dash__table th,
.dash__table td'));
        $this->assertStringContainsString('minmax(min(100%, 11rem), 1fr)', $this->rule($dashboard, '.dash__stats'));
        $this->assertStringContainsString('padding: 10px 14px', $this->rule($dashboard, '.dash__stat'));
        $this->assertStringContainsString('line-clamp: 2', $this->rule($dashboard, '.dash__clamp'));
    }

    public function testTheOfficeListCutsAVeryLongValueToTwoLinesAndKeepsTheWholeThingInTheTooltip(): void
    {
        $address = str_repeat('Longstreetname', 18);
        TableRegistry::getTableLocator()->get('JobRequests')->getConnection()
            ->update('job_requests', ['street_address' => $address], ['id' => 1]);

        $this->signInAsAdmin();
        $this->get('/job-requests/manage');

        $this->assertResponseOk();
        $this->assertResponseContains('<span class="dash__clamp" title="' . $address . '">');
        // The description preview is clamped too, and its tooltip holds the same text.
        $this->assertResponseContains('class="dash__preview dash__clamp"');
        $this->assertResponseContains('class="dash__table dash__table--job-requests"');
    }

    public function testThePlumberJobTableSitsInTheScrollingWrapper(): void
    {
        $this->session(['AdminAuth' => ['admin_id' => 2, 'admin_username' => 'staffuser', 'role' => 'staff']]);

        $this->get('/plumbers/jobs');

        $this->assertResponseOk();
        $this->assertMatchesRegularExpression(
            '/<div class="dash__table-wrap">\s*<table class="dash__table">/',
            (string)$this->_response->getBody(),
        );
    }
}
