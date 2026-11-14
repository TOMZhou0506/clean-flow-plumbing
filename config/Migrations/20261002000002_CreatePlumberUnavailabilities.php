<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * One-off exceptions to the standard Monday-Friday, 08:00-17:00 work week.
 * Times are minutes after midnight in the business's local timezone.
 */
class CreatePlumberUnavailabilities extends BaseMigration
{
    /** Match the imported admins key type, including its signedness. */
    public function up(): void
    {
        $isMysql = $this->getAdapter()->getAdapterType() === 'mysql';
        $unsigned = false;
        if ($isMysql) {
            $parent = $this->fetchRow("SHOW COLUMNS FROM `admins` LIKE 'admin_id'");
            if ($parent === false) {
                throw new RuntimeException('The admins.admin_id column is missing.');
            }
            $unsigned = str_contains(strtolower((string)$parent['Type']), 'unsigned');
        }

        if (!$this->hasTable('plumber_unavailabilities')) {
            $this->table('plumber_unavailabilities', ['collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('admin_id', 'integer', ['signed' => !$unsigned, 'null' => false])
                ->addColumn('unavailable_date', 'date', ['null' => false])
                ->addColumn('start_minute', 'integer', ['null' => false])
                ->addColumn('end_minute', 'integer', ['null' => false])
                ->addColumn('cancelled_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => false])
                ->addColumn('modified', 'datetime', ['null' => false])
                ->addIndex(['admin_id', 'unavailable_date', 'cancelled_at'], [
                    'name' => 'idx_plumber_unavailability_calendar',
                ])
                ->addForeignKey('admin_id', 'admins', 'admin_id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                    'constraint' => 'fk_plumber_unavailability_admin',
                ])
                ->create();

            return;
        }

        // MySQL may have committed CREATE TABLE before rejecting its foreign
        // key. Complete that partial migration without dropping any data.
        if ($isMysql) {
            $child = $this->fetchRow("SHOW COLUMNS FROM `plumber_unavailabilities` LIKE 'admin_id'");
            if ($child === false) {
                throw new RuntimeException('The plumber_unavailabilities.admin_id column is missing.');
            }
            $childUnsigned = str_contains(strtolower((string)$child['Type']), 'unsigned');
            if ($childUnsigned !== $unsigned) {
                $type = $unsigned ? 'INT UNSIGNED' : 'INT';
                $this->execute("ALTER TABLE `plumber_unavailabilities` MODIFY `admin_id` $type NOT NULL");
            }
            $foreignKey = $this->fetchRow(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plumber_unavailabilities'
                 AND COLUMN_NAME = 'admin_id' AND REFERENCED_TABLE_NAME = 'admins' LIMIT 1",
            );
            if ($foreignKey === false) {
                $this->table('plumber_unavailabilities')
                    ->addForeignKey('admin_id', 'admins', 'admin_id', [
                        'delete' => 'RESTRICT',
                        'update' => 'NO_ACTION',
                        'constraint' => 'fk_plumber_unavailability_admin',
                    ])
                    ->update();
            }
        }
    }

    /** Remove the table only on an explicit migration rollback. */
    public function down(): void
    {
        $this->table('plumber_unavailabilities')->drop()->save();
    }
}
