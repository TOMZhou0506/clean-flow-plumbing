<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Imported phpMyAdmin tables can be marked as migrated without having the
 * AUTO_INCREMENT and unique username index in CreateAdmins. Repair only the
 * missing pieces; a freshly migrated database already has both.
 */
class RepairImportedAdminsKeys extends BaseMigration
{
    /** Repair the imported MySQL table without changing existing account IDs. */
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        $idColumn = $this->fetchRow("SHOW COLUMNS FROM `admins` LIKE 'admin_id'");
        if ($idColumn === false) {
            throw new RuntimeException('The admins.admin_id column is missing.');
        }

        if (!str_contains(strtolower((string)$idColumn['Extra']), 'auto_increment')) {
            // Keep the imported column's signedness; changing it could break
            // relationships to existing audit and enquiry records.
            $type = strtolower((string)$idColumn['Type']);
            if (preg_match('/^int(?:\(\d+\))?(?: unsigned)?$/', $type) !== 1) {
                throw new RuntimeException('Unexpected admins.admin_id type: ' . $type);
            }
            $this->execute("ALTER TABLE `admins` MODIFY `admin_id` $type NOT NULL AUTO_INCREMENT");
        }

        $usernameIndexes = $this->fetchAll("SHOW INDEX FROM `admins` WHERE `Column_name` = 'admin_username'");
        foreach ($usernameIndexes as $index) {
            if ((int)$index['Non_unique'] === 0) {
                return;
            }
        }

        $duplicate = $this->fetchRow(
            'SELECT admin_username FROM admins GROUP BY admin_username HAVING COUNT(*) > 1 LIMIT 1',
        );
        if ($duplicate !== false) {
            throw new RuntimeException('Resolve duplicate admin usernames before adding the unique index.');
        }

        $this->execute('ALTER TABLE `admins` ADD UNIQUE INDEX `uq_admins_username` (`admin_username`)');
    }

    /** Do not undo a repair that makes account creation and login reliable. */
    public function down(): void
    {
    }
}
