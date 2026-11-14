<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Mailer\Transport\LogTransport;
use App\Service\MailDelivery;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Cake\Mailer\Transport\SmtpTransport;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;

/** Email goes out from the site mailbox, and a failure never breaks the page. */
class MailDeliveryTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $originalTransport = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTransport = (array)TransportFactory::getConfig('default');

        Log::drop('mail_test_info');
        Log::drop('mail_test_error');
        Log::setConfig('mail_test_info', ['className' => ArrayLog::class, 'levels' => ['info'], 'scopes' => ['email']]);
        Log::setConfig('mail_test_error', ['className' => ArrayLog::class, 'levels' => ['error']]);
    }

    protected function tearDown(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', $this->originalTransport);
        Log::drop('mail_test_info');
        Log::drop('mail_test_error');
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function logged(string $name): array
    {
        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine($name);

        return $engine->read();
    }

    public function testTheDefaultTransportWritesToTheLogAndSendsNothing(): void
    {
        $this->assertSame(LogTransport::class, $this->originalTransport['className']);
    }

    public function testTheMessageComesFromTheSiteMailboxWithTheSiteName(): void
    {
        $this->assertSame(
            ['manager@u26s2248.iedev.org' => 'Clean Flow Plumbing'],
            ((array)Mailer::getConfig('default'))['from'] ?? null,
        );

        $this->assertTrue((new MailDelivery())->send('testMessage', ['someone@example.com']));

        $written = implode("\n", $this->logged('mail_test_info'));
        $this->assertStringContainsString('From: Clean Flow Plumbing <manager@u26s2248.iedev.org>', $written);
        $this->assertStringContainsString('To: someone@example.com', $written);
        $this->assertStringContainsString('Subject: Clean Flow Plumbing email test', $written);
        $this->assertStringContainsString('Clean Flow Plumbing', $written);
    }

    public function testAFailedSendReturnsFalseAndIsLoggedWithoutTheAddress(): void
    {
        // Nothing listens on port 1, so connecting is refused straight away.
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', [
            'className' => SmtpTransport::class,
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 2,
            'password' => 'never-logged',
        ]);

        $sent = (new MailDelivery())->send('testMessage', ['private.person@example.com']);

        $this->assertFalse($sent);
        $errors = implode("\n", $this->logged('mail_test_error'));
        $this->assertStringContainsString('Email "testMessage" could not be sent', $errors);
        $this->assertStringNotContainsString('private.person@example.com', $errors);
        $this->assertStringNotContainsString('never-logged', $errors);
    }
}
