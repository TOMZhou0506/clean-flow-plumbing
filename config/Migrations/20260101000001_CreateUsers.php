<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * users
 *
 * I backfilled this. The table was created by hand in phpMyAdmin, so it had
 * no migration, which meant the test database (built purely from migrations by
 * tests/bootstrap.php) never had a users table and every test touching
 * UsersFixture failed before I even started.
 *
 * I guard it with hasTable() because the table already exists in our
 * development database. On an empty one, such as the SQLite connection my
 * tests use, it gets created.
 *
 * I derived the column definitions from UsersTable::validationDefault() and
 * the User entity annotations. If the live table differs, correct this file
 * rather than the live table, because the migration is the source of truth
 * now.
 */
class CreateUsers extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('users')) {
            return;
        }

        $this->table('users', [
            'id' => false,
            'primary_key' => ['id'],
        ])
            // UUID, not an auto-increment integer. The entity annotates $id
            // as a string and PropertiesTable validates user_id with ->uuid(),
            // so the application already assumed 36-char ids before I got
            // here.
            ->addColumn('id', 'uuid', [
                'null' => false,
            ])
            ->addColumn('email', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            // 96 matches the maxLength rule in UsersTable. That is wide
            // enough for a bcrypt hash at 60 characters, with room if we ever
            // change algorithm.
            ->addColumn('password', 'string', [
                'limit' => 96,
                'null' => false,
            ])
            ->addColumn('first_name', 'string', [
                'limit' => 128,
                'null' => false,
            ])
            ->addColumn('last_name', 'string', [
                'limit' => 128,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            // Unique, because two accounts sharing an email would make the
            // authentication identifier ambiguous.
            ->addIndex(['email'], [
                'name' => 'uq_users_email',
                'unique' => true,
            ])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        // I left this empty on purpose. The migration exists to describe a
        // table that predates it, so dropping users on a rollback would
        // destroy real accounts I never created.
    }
}
