<?php
declare(strict_types=1);

namespace App\Test\TestCase\Validation;

use App\Validation\StrongPassword;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StrongPasswordValidationTest extends TestCase
{
    #[DataProvider('invalidPasswords')]
    public function testInvalidPasswordsAreRejected(string $password): void
    {
        $this->assertFalse(StrongPassword::isValid($password));
    }

    public static function invalidPasswords(): iterable
    {
        yield 'too short' => ['Ab!8'];
        yield 'too long' => ['Long!' . str_repeat('A', 27) . '8'];
        yield 'no uppercase' => ['password!8'];
        yield 'no lowercase' => ['PASSWORD!8'];
        yield 'no number' => ['Password!'];
        yield 'no special character' => ['Password8'];
        yield 'special character cannot be a space' => ['Password 8'];
    }

    #[DataProvider('validPasswords')]
    public function testValidPasswordsAreAccepted(string $password): void
    {
        $this->assertTrue(StrongPassword::isValid($password));
    }

    public static function validPasswords(): iterable
    {
        yield ['Flow!8Blue'];
        yield ['Blue#2026A'];
        yield ['Secure$135Word'];
        // Runs of consecutive numbers are allowed.
        yield 'ascending numbers' => ['Safe!123Word'];
        yield 'long ascending numbers' => ['Safe!4567Word'];
        yield 'descending numbers' => ['Safe!987Word'];
        yield 'only consecutive numbers' => ['Blue!Word123456'];
    }

    public function testTheConsecutiveNumbersMessageIsGone(): void
    {
        $this->assertFalse(defined(StrongPassword::class . '::SEQUENCE'));
        $this->assertSame([], StrongPassword::errors('Safe!123Word'));
        $this->assertStringNotContainsString(
            'consecutive',
            (string)file_get_contents(WWW_ROOT . 'js' . DS . 'password-validation.js'),
        );
    }

    public function testCustomerTableAppliesThePasswordRule(): void
    {
        $customer = TableRegistry::getTableLocator()->get('Customers')->newEntity([
            'cust_email' => 'test@example.com',
            'cust_fname' => 'Test',
            'cust_lname' => 'Customer',
            'password' => 'safe!word8',
            'confirm_password' => 'safe!word8',
        ]);

        $this->assertContains(StrongPassword::UPPERCASE, array_values($customer->getError('password')));
    }

    public function testCustomerTableAcceptsConsecutiveNumbers(): void
    {
        $customer = TableRegistry::getTableLocator()->get('Customers')->newEntity([
            'cust_email' => 'test@example.com',
            'cust_fname' => 'Test',
            'cust_lname' => 'Customer',
            'password' => 'Safe!123Word',
            'confirm_password' => 'Safe!123Word',
        ]);

        $this->assertSame([], $customer->getError('password'));
    }

    public function testCustomerTableReturnsSeparateMessagesForEachBrokenRule(): void
    {
        $customer = TableRegistry::getTableLocator()->get('Customers')->newEntity([
            'cust_email' => 'test@example.com',
            'cust_fname' => 'Test',
            'cust_lname' => 'Customer',
            'password' => 'short',
            'confirm_password' => 'short',
        ]);
        $errors = array_values($customer->getError('password'));

        $this->assertContains(StrongPassword::LENGTH, $errors);
        $this->assertContains(StrongPassword::UPPERCASE, $errors);
        $this->assertContains(StrongPassword::NUMBER, $errors);
        $this->assertContains(StrongPassword::SPECIAL, $errors);
    }

    public function testAdminTableUsesTheSamePasswordRules(): void
    {
        $admin = TableRegistry::getTableLocator()->get('Admins')->newEntity([
            'admin_username' => 'newadmin',
            'admin_password' => 'safe!word8',
            'confirm_password' => 'safe!word8',
        ]);

        $this->assertContains(
            StrongPassword::UPPERCASE,
            array_values($admin->getError('admin_password')),
        );
    }

    public function testAdminTableAcceptsConsecutiveNumbers(): void
    {
        $admin = TableRegistry::getTableLocator()->get('Admins')->newEntity([
            'admin_username' => 'newadmin',
            'admin_password' => 'Safe!987Word',
            'confirm_password' => 'Safe!987Word',
        ]);

        $this->assertSame([], $admin->getError('admin_password'));
    }

    public function testAdminConfirmationMustMatch(): void
    {
        $admin = TableRegistry::getTableLocator()->get('Admins')->newEntity([
            'admin_username' => 'newadmin',
            'admin_password' => 'Secure!8Word',
            'confirm_password' => 'Different!8Word',
        ]);

        $this->assertContains(
            'Passwords do not match.',
            array_values($admin->getError('confirm_password')),
        );
    }

    public function testValidPasswordPassesBothCustomerAndAdminTables(): void
    {
        $password = 'Secure!8Word';
        $customer = TableRegistry::getTableLocator()->get('Customers')->newEntity([
            'cust_email' => 'test@example.com',
            'cust_fname' => 'Test',
            'cust_lname' => 'Customer',
            'password' => $password,
            'confirm_password' => $password,
        ]);
        $admin = TableRegistry::getTableLocator()->get('Admins')->newEntity([
            'admin_username' => 'newadmin',
            'admin_password' => $password,
            'confirm_password' => $password,
        ]);

        $this->assertSame([], $customer->getError('password'));
        $this->assertSame([], $customer->getError('confirm_password'));
        $this->assertSame([], $admin->getError('admin_password'));
        $this->assertSame([], $admin->getError('confirm_password'));
    }
}
