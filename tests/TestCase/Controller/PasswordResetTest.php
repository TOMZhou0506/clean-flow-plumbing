<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\PasswordResetService;
use App\Test\Fixture\AdminsFixture;
use Authentication\IdentityInterface;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Forgot password for office accounts: a 6 digit code sent to the account's own mailbox.
 */
class PasswordResetTest extends TestCase
{
    use IntegrationTestTrait;

    private const NEW_PASSWORD = 'BrandNewPass#2027';

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.Customers',
        'app.LoginRateLimits',
        'app.PasswordResets',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Log::drop('reset_mail');
        Log::setConfig('reset_mail', ['className' => ArrayLog::class, 'levels' => ['info'], 'scopes' => ['email']]);
    }

    protected function tearDown(): void
    {
        Log::drop('reset_mail');
        DateTime::setTestNow(null);
        Configure::write('LoginRateLimit.enabled', false);
        parent::tearDown();
    }

    /**
     * @return list<string> Every email written to the log so far.
     */
    private function sentEmails(): array
    {
        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('reset_mail');

        return $engine->read();
    }

    /**
     * The code in the most recent email.
     */
    private function lastCode(): string
    {
        $emails = $this->sentEmails();
        $this->assertNotEmpty($emails, 'No email was sent.');
        $this->assertSame(1, preg_match('/Your code is: (\d{6})/', (string)end($emails), $match));

        return $match[1];
    }

    private function accounts(): Table
    {
        return TableRegistry::getTableLocator()->get('Admins');
    }

    private function resets(): Table
    {
        return TableRegistry::getTableLocator()->get('PasswordResets');
    }

    private function signedInIdentity(): ?IdentityInterface
    {
        $identity = $this->_controller?->getRequest()->getAttribute('identity');

        return $identity instanceof IdentityInterface ? $identity : null;
    }

    private function askForCode(string $email): void
    {
        $this->enableCsrfToken();
        $this->post('/forgot-password', ['email' => $email]);
    }

    private function useCode(string $email, string $code, string $password = self::NEW_PASSWORD, ?string $confirm = null): void
    {
        $this->enableCsrfToken();
        $this->post('/reset-password', [
            'email' => $email,
            'code' => $code,
            'admin_password' => $password,
            'confirm_password' => $confirm ?? $password,
        ]);
    }

    private function passwordIs(int $adminId, string $password): bool
    {
        return password_verify($password, (string)$this->accounts()->get($adminId)->admin_password);
    }

    public function testTheSignInPageLinksToForgotPasswordForOfficeAccountsOnly(): void
    {
        $this->get('/login');

        $this->assertResponseOk();
        $this->assertResponseContains('Forgot password?');
        $this->assertResponseContains('/forgot-password');
        $this->assertResponseContains('staff and admin accounts only');
    }

    public function testBothPagesLoadWithoutSigningIn(): void
    {
        $this->get('/forgot-password');
        $this->assertResponseOk();
        $this->assertResponseContains('name="email"');
        $this->assertResponseContains('Staff and admin accounts only');

        $this->get('/reset-password');
        $this->assertResponseOk();
        $this->assertResponseContains('name="code"');
        $this->assertResponseContains('name="admin_password"');
        $this->assertResponseContains('name="confirm_password"');
    }

    public function testAnOfficeAccountIsEmailedASixDigitCode(): void
    {
        foreach (['hannah@example.com', 'staffuser@example.com', 'manageruser@example.com'] as $email) {
            $this->cleanup();
            $this->askForCode($email);

            $this->assertRedirect('/reset-password', $email);
            $emails = implode("\n", $this->sentEmails());
            $this->assertStringContainsString('To: ' . $email, $emails);
        }

        $emails = implode("\n", $this->sentEmails());
        $this->assertStringContainsString('From: Clean Flow Plumbing <manager@u26s2248.iedev.org>', $emails);
        $this->assertStringContainsString('Subject: Your Clean Flow Plumbing password reset code', $emails);
        $this->assertStringContainsString('Clean Flow Plumbing', $emails);
        $this->assertStringContainsString('15 minutes', $emails);
        $this->assertMatchesRegularExpression('/Your code is: \d{6}/', $emails);
    }

    public function testTheAnswerIsTheSameWhetherOrNotTheAccountExists(): void
    {
        $message = 'If that email belongs to an account, a 6 digit code has been sent to it. It works for 15 minutes.';

        $this->askForCode('hannah@example.com');
        $this->assertRedirect('/reset-password');
        $this->assertFlashMessage($message);
        $this->assertCount(1, $this->sentEmails());

        $this->cleanup();
        $this->askForCode('nobody@example.com');
        $this->assertRedirect('/reset-password');
        $this->assertFlashMessage($message);

        $this->assertCount(1, $this->sentEmails(), 'An unknown address must not be sent anything.');
        $this->assertSame(1, $this->resets()->find()->count());
    }

    public function testNoCodeIsSentToADeactivatedAccountOrACustomer(): void
    {
        $this->accounts()->getConnection()->update('admins', ['active' => 0], ['admin_id' => 1]);

        $this->askForCode('hannah@example.com');
        $this->assertRedirect('/reset-password');
        $this->cleanup();
        $this->askForCode('kash@example.com');
        $this->assertRedirect('/reset-password');

        $this->assertSame([], $this->sentEmails());
        $this->assertSame(0, $this->resets()->find()->count());
    }

    public function testAnyCaseOrSpacingOfTheAddressWorks(): void
    {
        $this->askForCode('  Hannah@Example.COM ');

        $this->assertRedirect('/reset-password');
        $this->assertCount(1, $this->sentEmails());
    }

    public function testOnlyAHashOfTheCodeIsStored(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();

        $row = $this->resets()->find()->firstOrFail();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, $row->code_hash);
        $this->assertTrue(password_verify($code, $row->code_hash));
        $this->assertSame(1, $row->admin_id);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->used);
    }

    public function testTheRightCodeSetsANewPasswordAndTheAccountSignsInWithIt(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();

        $this->cleanup();
        $this->useCode('hannah@example.com', $code);

        $this->assertRedirect('/login');
        $this->assertTrue($this->passwordIs(1, self::NEW_PASSWORD));
        $this->assertFalse($this->passwordIs(1, AdminsFixture::PASSWORD));
        $this->assertSame(
            1,
            TableRegistry::getTableLocator()->get('AuditLogs')->find()
                ->where(['action' => 'admin.password_reset', 'user_id' => 1])->count(),
        );

        $this->cleanup();
        $this->enableCsrfToken();
        $this->post('/login', ['email' => 'hannah@example.com', 'password' => self::NEW_PASSWORD]);
        $this->assertSame(1, (int)$this->signedInIdentity()?->offsetGet('admin_id'));

        $this->cleanup();
        $this->enableCsrfToken();
        $this->post('/login', ['email' => 'hannah@example.com', 'password' => AdminsFixture::PASSWORD]);
        $this->assertNull($this->signedInIdentity());
    }

    public function testACodeCanBeUsedOnlyOnce(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();
        $this->cleanup();
        $this->useCode('hannah@example.com', $code);
        $this->assertRedirect('/login');

        $this->cleanup();
        $this->useCode('hannah@example.com', $code, 'AnotherPass#2028');

        $this->assertResponseOk();
        $this->assertResponseContains('That code is not valid or has expired.');
        $this->assertTrue($this->passwordIs(1, self::NEW_PASSWORD));
    }

    public function testACodeStopsWorkingAfterFifteenMinutes(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();

        DateTime::setTestNow(DateTime::now()->addMinutes(PasswordResetService::MINUTES + 1));
        $this->cleanup();
        $this->useCode('hannah@example.com', $code);

        $this->assertResponseOk();
        $this->assertResponseContains('That code is not valid or has expired.');
        $this->assertTrue($this->passwordIs(1, AdminsFixture::PASSWORD));
    }

    public function testACodeStillWorksJustBeforeItExpires(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();

        DateTime::setTestNow(DateTime::now()->addMinutes(PasswordResetService::MINUTES - 1));
        $this->cleanup();
        $this->useCode('hannah@example.com', $code);

        $this->assertRedirect('/login');
    }

    public function testAWrongCodeIsRefusedWithoutChangingThePassword(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->cleanup();
        $this->useCode('hannah@example.com', $wrong);

        $this->assertResponseOk();
        $this->assertResponseContains('That code is not valid or has expired.');
        $this->assertTrue($this->passwordIs(1, AdminsFixture::PASSWORD));
        $this->assertSame(1, $this->resets()->find()->firstOrFail()->attempts);
    }

    public function testFiveWrongGuessesCancelTheCodeEvenForTheRightOne(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < PasswordResetService::MAX_ATTEMPTS; $i++) {
            $this->cleanup();
            $this->useCode('hannah@example.com', $wrong);
            $this->assertResponseContains('That code is not valid or has expired.');
        }

        $this->cleanup();
        $this->useCode('hannah@example.com', $code);

        $this->assertResponseOk();
        $this->assertResponseContains('That code is not valid or has expired.');
        $this->assertTrue($this->passwordIs(1, AdminsFixture::PASSWORD));
    }

    public function testNonsenseCodesAreRefused(): void
    {
        $this->askForCode('hannah@example.com');

        foreach (['', 'abcdef', '12345', '1234567', "12345\n"] as $nonsense) {
            $this->cleanup();
            $this->useCode('hannah@example.com', $nonsense);

            $this->assertResponseOk($nonsense);
            $this->assertResponseContains('That code is not valid or has expired.', $nonsense);
        }
        $this->assertTrue($this->passwordIs(1, AdminsFixture::PASSWORD));
    }

    public function testAskingAgainCancelsTheEarlierCode(): void
    {
        $this->askForCode('hannah@example.com');
        $first = $this->lastCode();
        $this->cleanup();
        $this->askForCode('hannah@example.com');
        $second = $this->lastCode();

        if ($first !== $second) {
            $this->cleanup();
            $this->useCode('hannah@example.com', $first);
            $this->assertResponseOk();
            $this->assertResponseContains('That code is not valid or has expired.');
        }

        $this->cleanup();
        $this->useCode('hannah@example.com', $second);
        $this->assertRedirect('/login');
    }

    public function testNoMoreThanThreeCodesAreSentInFifteenMinutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->cleanup();
            $this->askForCode('hannah@example.com');
            $this->assertRedirect('/reset-password');
        }

        $this->assertCount(PasswordResetService::MAX_CODES_PER_WINDOW, $this->sentEmails());

        DateTime::setTestNow(DateTime::now()->addMinutes(PasswordResetService::MINUTES + 1));
        $this->cleanup();
        $this->askForCode('hannah@example.com');
        $this->assertCount(PasswordResetService::MAX_CODES_PER_WINDOW + 1, $this->sentEmails());
    }

    public function testAWeakPasswordIsRefusedAndTheCodeStillWorksAfterwards(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();

        $this->cleanup();
        $this->useCode('hannah@example.com', $code, 'short');
        $this->assertResponseOk();
        $this->assertResponseContains('The password could not be changed.');
        $this->assertTrue($this->passwordIs(1, AdminsFixture::PASSWORD));

        $this->cleanup();
        $this->useCode('hannah@example.com', $code, '');
        $this->assertResponseOk();
        $this->assertTrue($this->passwordIs(1, AdminsFixture::PASSWORD));

        $this->cleanup();
        $this->useCode('hannah@example.com', $code);
        $this->assertRedirect('/login');
        $this->assertTrue($this->passwordIs(1, self::NEW_PASSWORD));
    }

    public function testAMismatchedConfirmationIsRefused(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();

        $this->cleanup();
        $this->useCode('hannah@example.com', $code, self::NEW_PASSWORD, 'Different#2027Pass');

        $this->assertResponseOk();
        $this->assertResponseContains('Passwords do not match.');
        $this->assertTrue($this->passwordIs(1, AdminsFixture::PASSWORD));
    }

    public function testACodeOnlyWorksForItsOwnAccount(): void
    {
        $this->askForCode('hannah@example.com');
        $code = $this->lastCode();

        $this->cleanup();
        $this->useCode('staffuser@example.com', $code);

        $this->assertResponseOk();
        $this->assertResponseContains('That code is not valid or has expired.');
        $this->assertTrue($this->passwordIs(2, AdminsFixture::PASSWORD));
    }

    public function testAnUnknownAddressGetsTheSameRefusalAsAWrongCode(): void
    {
        $this->useCode('nobody@example.com', '123456');

        $this->assertResponseOk();
        $this->assertResponseContains('That code is not valid or has expired.');
    }

    public function testSessionsThatSignedInWithTheOldPasswordEnd(): void
    {
        $oldHash = (string)$this->accounts()->get(2)->admin_password;
        $session = [
            'admin_id' => 2,
            'admin_username' => 'staffuser',
            'admin_email' => 'staffuser@example.com',
            'admin_password' => $oldHash,
            'role' => 'staff',
        ];

        // Before the reset the session works.
        $this->session(['AdminAuth' => $session]);
        $this->get('/plumbers/dashboard');
        $this->assertResponseOk();

        $this->cleanup();
        $this->askForCode('staffuser@example.com');
        $code = $this->lastCode();
        $this->cleanup();
        $this->useCode('staffuser@example.com', $code);
        $this->assertRedirect('/login');

        // The same session, which signed in with the old password, no longer does.
        $this->cleanup();
        $this->session(['AdminAuth' => $session]);
        $this->get('/plumbers/dashboard');
        $this->assertRedirectContains('/login');
    }

    public function testASessionWithTheCurrentPasswordKeepsWorking(): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => 2,
            'admin_username' => 'staffuser',
            'admin_password' => (string)$this->accounts()->get(2)->admin_password,
            'role' => 'staff',
        ]]);

        $this->get('/plumbers/dashboard');

        $this->assertResponseOk();
    }

    public function testAskingForCodesIsRateLimitedPerAddress(): void
    {
        Configure::write('LoginRateLimit.enabled', true);

        for ($i = 0; $i < 5; $i++) {
            $this->cleanup();
            $this->askForCode('hannah@example.com');
            $this->assertRedirect('/reset-password');
        }

        $this->cleanup();
        Configure::write('debug', false);
        $this->askForCode('hannah@example.com');
        $this->assertResponseCode(429);
    }

    public function testGuessingCodesIsRateLimitedPerAddress(): void
    {
        Configure::write('LoginRateLimit.enabled', true);

        for ($i = 0; $i < 5; $i++) {
            $this->cleanup();
            $this->useCode('hannah@example.com', '123456');
            $this->assertResponseOk();
        }

        $this->cleanup();
        Configure::write('debug', false);
        $this->useCode('hannah@example.com', '123456');
        $this->assertResponseCode(429);
    }
}
