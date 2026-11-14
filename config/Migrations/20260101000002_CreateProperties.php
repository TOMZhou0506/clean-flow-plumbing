<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * properties
 *
 * I backfilled this for the same reason as CreateUsers: the table was created by
 * hand, so the test database had no way to build it.
 *
 * Definitions derived from PropertiesTable::validationDefault() and the
 * Property entity's @property annotations.
 */
class CreateProperties extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('properties')) {
            return;
        }

        $this->table('properties', [
            'id' => false,
            'primary_key' => ['id'],
        ])
            ->addColumn('id', 'uuid', [
                'null' => false,
            ])
            ->addColumn('user_id', 'uuid', [
                'null' => false,
            ])
            // text, not string. PropertiesFixture holds a ~350 character
            // address, so a VARCHAR(255) would reject the team's own test
            // data, and PropertiesTable sets no maxLength rule.
            ->addColumn('address', 'text', [
                'null' => false,
            ])
            // decimal, not float. Money in a float loses precision on
            // arithmetic; 12,2 covers values up to 9,999,999,999.99.
            ->addColumn('price', 'decimal', [
                'precision' => 12,
                'scale' => 2,
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
            ->addIndex(['user_id'], [
                'name' => 'idx_properties_user',
            ])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        // See CreateUsers::down(). This describes a pre-existing table.
    }
}
