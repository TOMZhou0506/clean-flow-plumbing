<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Rehashes admin passwords that were stored as plaintext.
 *
 * Background, because this looks alarming and should. Admin::_setPassword()
 * never fired: CakePHP derives the mutator name from the field, so it would
 * have needed to be _setAdminPassword() to match the admin_password column.
 * Every account created through /admins/add therefore stored the password
 * exactly as typed, and login worked because it compared plaintext against
 * plaintext. US 2.4's fourth security check is to confirm passwords are
 * hashed, and today they are not.
 *
 * I can convert them precisely because they are plaintext: the value in the
 * column is the password, so I hash it and write the hash back. Nobody's
 * login changes. Had they been hashed with something weak I could not do
 * this, because a hash cannot be read back.
 *
 * I detect an already-hashed row rather than rehashing blindly. A bcrypt hash
 * from PHP is 60 characters and starts with $2y$, $2a$ or $2b$, and hashing a
 * hash would lock out anyone already stored correctly. Note that the two
 * seeded accounts carry $2b$ hashes, which is the prefix Node's bcryptjs
 * writes rather than PHP's; they are left alone here, but somebody should
 * confirm those two accounts can actually log in.
 *
 * IMPORTANT: this fixes how the passwords are stored, not the fact that they
 * were exposed. Any password that sat in that column in plaintext was
 * readable by anyone with database access, including everyone on the team
 * with phpMyAdmin open. Treat them as compromised and have people set new
 * ones.
 *
 * There is no down(). Reversing this would mean turning hashes back into
 * plaintext, which is both impossible and the opposite of what we want.
 */
class RehashPlaintextAdminPasswords extends BaseMigration
{
    public function up(): void
    {
        $rows = $this->fetchAll('SELECT admin_id, admin_password FROM admins');

        foreach ($rows as $row) {
            $stored = (string)$row['admin_password'];

            if ($stored === '' || $this->looksHashed($stored)) {
                continue;
            }

            $this->execute(
                'UPDATE admins SET admin_password = ? WHERE admin_id = ?',
                [password_hash($stored, PASSWORD_DEFAULT), $row['admin_id']],
            );
        }
    }

    public function down(): void
    {
        // Deliberately empty. See the class comment.
    }

    /**
     * Whether a stored value is already a bcrypt hash.
     *
     * @param string $value The stored password column value.
     * @return bool
     */
    private function looksHashed(string $value): bool
    {
        return strlen($value) === 60
            && preg_match('/^\$2[aby]\$\d{2}\$/', $value) === 1;
    }
}
