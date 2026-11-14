<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/** The one-time command that makes an existing admin the manager, found by sign-in email. */
class PromoteManagerCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = ['app.Admins'];

    private function demoteTheFixtureManager(): void
    {
        TableRegistry::getTableLocator()->get('Admins')->getConnection()
            ->update('admins', ['role' => 'staff'], ['admin_id' => 3]);
    }

    public function testItPromotesTheAdminWithThatEmail(): void
    {
        $this->demoteTheFixtureManager();

        $this->exec('promote_manager hannah@example.com');

        $this->assertExitSuccess();
        $this->assertOutputContains('Manager account created');
        $this->assertSame('manager', TableRegistry::getTableLocator()->get('Admins')->get(1)->role);
    }

    public function testTheEmailIsMatchedWhateverTheCase(): void
    {
        $this->demoteTheFixtureManager();

        $this->exec('promote_manager Hannah@Example.COM');

        $this->assertExitSuccess();
        $this->assertSame('manager', TableRegistry::getTableLocator()->get('Admins')->get(1)->role);
    }

    public function testAUsernameIsNotAnAccount(): void
    {
        $this->demoteTheFixtureManager();

        $this->exec('promote_manager hannah');

        $this->assertExitError();
        $this->assertErrorContains('No active admin with that exact email was found.');
        $this->assertSame('admin', TableRegistry::getTableLocator()->get('Admins')->get(1)->role);
    }

    public function testItRefusesWhenAManagerAlreadyExists(): void
    {
        $this->exec('promote_manager hannah@example.com');

        $this->assertExitError();
        $this->assertErrorContains('A manager account already exists.');
        $this->assertSame('admin', TableRegistry::getTableLocator()->get('Admins')->get(1)->role);
    }
}
