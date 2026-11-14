<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Office accounts sign in with an email address.
 *
 * admins gets a required, unique admin_email column. admin_username stays as the
 * name shown in lists, greetings and the audit trail, but it no longer signs
 * anyone in.
 *
 * Accounts that already exist are filled in before the column is made required:
 * a username that already is an email address is kept, lower-cased, and any other
 * username becomes username@u26s2248.iedev.org. That is a placeholder, not a real
 * mailbox, so an account filled in this way needs a real address from the manager
 * before its owner can sign in. A database that is dropped and re-imported from the
 * project's SQL file already has the real addresses and never meets this step.
 *
 * Each step checks whether it is already done, so the same change can be applied by
 * hand on a server without a command line and recorded afterwards.
 *
 * Run `bin\cake cache clear_all` afterwards or the ORM will not see the column.
 */
class AddEmailToAdmins extends BaseMigration
{
    private const PLACEHOLDER_DOMAIN = 'u26s2248.iedev.org';

    /**
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('admins');

        if (!$table->hasColumn('admin_email')) {
            $table
                ->addColumn('admin_email', 'string', [
                    'limit' => 254,
                    'null' => true,
                    'default' => null,
                    'after' => 'admin_username',
                ])
                ->update();
        }

        $this->fillMissingEmails();

        $table = $this->table('admins');
        $table->changeColumn('admin_email', 'string', ['limit' => 254, 'null' => false]);
        if (!$table->hasIndexByName('uq_admins_email')) {
            $table->addIndex(['admin_email'], ['unique' => true, 'name' => 'uq_admins_email']);
        }
        $table->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('admins');

        if ($table->hasIndexByName('uq_admins_email')) {
            $table->removeIndexByName('uq_admins_email')->update();
        }
        if ($table->hasColumn('admin_email')) {
            $table->removeColumn('admin_email')->update();
        }
    }

    /**
     * Gives every account without an address one, never reusing an address already taken.
     */
    private function fillMissingEmails(): void
    {
        $taken = [];
        foreach ($this->fetchAll('SELECT admin_email FROM admins WHERE admin_email IS NOT NULL') as $row) {
            $taken[mb_strtolower((string)$row['admin_email'])] = true;
        }

        $accounts = $this->fetchAll(
            'SELECT admin_id, admin_username FROM admins '
            . "WHERE admin_email IS NULL OR admin_email = '' ORDER BY admin_id",
        );
        foreach ($accounts as $account) {
            $name = mb_strtolower(trim((string)$account['admin_username']));
            $email = str_contains($name, '@')
                ? $name
                : preg_replace('/\s+/', '.', $name) . '@' . self::PLACEHOLDER_DOMAIN;

            if (isset($taken[$email])) {
                [$local, $domain] = array_pad(explode('@', $email, 2), 2, self::PLACEHOLDER_DOMAIN);
                $email = $local . '.' . $account['admin_id'] . '@' . $domain;
            }
            $taken[$email] = true;

            $this->getUpdateBuilder()
                ->update('admins')
                ->set('admin_email', $email)
                ->where(['admin_id' => $account['admin_id']])
                ->execute();
        }
    }
}
