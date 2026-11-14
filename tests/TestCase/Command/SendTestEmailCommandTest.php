<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Mailer\Transport\SmtpTransport;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;

/** The command that proves email sending works on a server. */
class SendTestEmailCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    /**
     * @var array<string, mixed>
     */
    private array $originalTransport = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTransport = (array)TransportFactory::getConfig('default');
    }

    protected function tearDown(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', $this->originalTransport);
        parent::tearDown();
    }

    public function testItSendsAndNamesTheSender(): void
    {
        $this->exec('send_test_email someone@example.com');

        $this->assertExitSuccess();
        $this->assertOutputContains('From:      Clean Flow Plumbing <manager@u26s2248.iedev.org>');
        $this->assertOutputContains('Handed to the mail server');
    }

    public function testItRefusesAnInvalidAddress(): void
    {
        $this->exec('send_test_email not-an-address');

        $this->assertExitError();
        $this->assertErrorContains('That is not a valid email address. Nothing was sent.');
    }

    public function testItReportsAFailureAndNeverPrintsThePassword(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', [
            'className' => SmtpTransport::class,
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 2,
            'username' => 'manager@u26s2248.iedev.org',
            'password' => 'secret-mailbox-password',
        ]);

        $this->exec('send_test_email someone@example.com');

        $this->assertExitError();
        $this->assertErrorContains('The email could not be sent');
        $this->assertOutputContains('Server:    127.0.0.1:1');
        $this->assertOutputNotContains('secret-mailbox-password');
        $this->assertStringNotContainsString('secret-mailbox-password', implode("
", $this->_err->messages()));
    }
}
