<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         1.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\Constraint\Response\StatusCode;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * PagesControllerTest class
 */
class PagesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
    ];

    public function testHomeDashboardLinkFollowsCurrentOfficeRole(): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => 3,
            'admin_username' => 'manageruser',
            'role' => 'admin',
        ]]);
        $this->get('/pages/home');
        $this->assertResponseOk();
        $this->assertResponseContains('Manager dashboard');
        // The same place the manager lands on after signing in.
        $this->assertResponseContains('href="/manager-jobs"');
        $this->assertResponseNotContains('Admin dashboard');

        $this->session(['AdminAuth' => [
            'admin_id' => 1,
            'admin_username' => 'hannah',
            'role' => 'admin',
        ]]);
        $this->get('/pages/home');
        $this->assertResponseOk();
        $this->assertResponseContains('Admin dashboard');
        $this->assertResponseContains('href="/job-requests/manage"');
        $this->assertResponseNotContains('Manager dashboard');

        $this->session(['AdminAuth' => [
            'admin_id' => 2,
            'admin_username' => 'staffuser',
            'role' => 'staff',
        ]]);
        $this->get('/pages/home');
        $this->assertResponseOk();
        $this->assertResponseContains('Plumber dashboard');
        $this->assertResponseContains('href="/plumbers/dashboard"');
        $this->assertResponseNotContains('Admin dashboard');
    }

    /**
     * testDisplay method
     *
     * @return void
     */
    public function testDisplay()
    {
        Configure::write('debug', true);
        $this->get('/pages/home');
        $this->assertResponseOk();
        // This used to assert on 'CakePHP', which was the skeleton welcome
        // page. Jingdong replaced that template with our landing page, so the
        // only 'CakePHP' left in home.php sits in a PHP comment on line 7 and
        // never reaches the response. I point the assertion at the client's
        // name and the page heading instead, so it now guards something we
        // actually care about staying on the page.
        $this->assertResponseContains('Clean Flow Plumbing');
        $this->assertResponseContains('Reliable plumbing, done right.');
        // Not '<html>'. home.php calls disableAutoLayout() and emits its own
        // document, opening with <html lang="en">, so the bare tag never
        // appears. This assertion was failing too; it just never ran, because
        // the 'CakePHP' one above it failed first and stopped the test.
        // Asserting on the lang attribute also keeps it there, which screen
        // readers need to pick the right pronunciation.
        $this->assertResponseContains('<html lang="en">');
    }

    /**
     * Test that missing template renders 404 page in production
     *
     * @return void
     */
    public function testMissingTemplate()
    {
        Configure::write('debug', false);
        $this->get('/pages/not_existing');

        $this->assertResponseError();
        $this->assertResponseContains('Error');
    }

    /**
     * Test that missing template in debug mode renders missing_template error page
     *
     * @return void
     */
    public function testMissingTemplateInDebug()
    {
        Configure::write('debug', true);
        $this->get('/pages/not_existing');

        $this->assertResponseFailure();
        $this->assertResponseContains('Missing Template');
        $this->assertResponseContains('stack-frames');
        $this->assertResponseContains('not_existing.php');
    }

    /**
     * Test directory traversal protection
     *
     * @return void
     */
    public function testDirectoryTraversalProtection()
    {
        $this->get('/pages/../Layout/ajax');
        $this->assertResponseCode(403);
        $this->assertResponseContains('Forbidden');
    }

    /**
     * Test that CSRF protection is applied to page rendering.
     *
     * @return void
     */
    public function testCsrfAppliedError()
    {
        $this->post('/pages/home', ['hello' => 'world']);

        $this->assertResponseCode(403);
        $this->assertResponseContains('CSRF');
    }

    /**
     * Test that CSRF protection is applied to page rendering.
     *
     * @return void
     */
    public function testCsrfAppliedOk()
    {
        $this->enableCsrfToken();
        $this->post('/pages/home', ['hello' => 'world']);

        $this->assertThat(403, $this->logicalNot(new StatusCode($this->_response)));
        $this->assertResponseNotContains('CSRF');
    }
}
