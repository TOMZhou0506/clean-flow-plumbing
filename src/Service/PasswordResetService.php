<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Admin;
use App\Validation\StrongPassword;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Resetting an office account's password with a code sent to its own mailbox.
 *
 * The rules, in one place:
 *  - a code is six digits, valid for 15 minutes, usable once;
 *  - five wrong guesses cancel it;
 *  - asking for a new code cancels the earlier one;
 *  - an account can be sent at most three codes in 15 minutes;
 *  - only a hash of the code is stored;
 *  - an unknown or deactivated address looks exactly like a known one from outside.
 */
class PasswordResetService
{
    use LocatorAwareTrait;

    public const MINUTES = 15;
    public const MAX_ATTEMPTS = 5;
    public const MAX_CODES_PER_WINDOW = 3;

    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const PASSWORD = 'password';

    private MailDelivery $mail;

    /**
     * @param \App\Service\MailDelivery|null $mail How the code is sent.
     */
    public function __construct(?MailDelivery $mail = null)
    {
        $this->mail = $mail ?? new MailDelivery();
    }

    /**
     * Send a code to the account that owns this email, if there is one.
     *
     * Returns without saying whether an account exists, and the caller must show the
     * same message either way.
     *
     * @param string $email What was typed.
     * @return void
     */
    public function requestCode(string $email): void
    {
        $admin = $this->findAccount($email);
        if ($admin === null) {
            $this->spendTheSameEffort();

            return;
        }

        $resets = $this->fetchTable('PasswordResets');
        $recent = $resets->find()
            ->where(['admin_id' => $admin->admin_id, 'created >=' => DateTime::now()->subMinutes(self::MINUTES)])
            ->count();
        if ($recent >= self::MAX_CODES_PER_WINDOW) {
            Log::warning('Too many password reset codes requested for account #' . $admin->admin_id);

            return;
        }

        $this->cancelOpenCodes((int)$admin->admin_id);

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $entry = $resets->newEmptyEntity();
        $entry->set('admin_id', $admin->admin_id);
        $entry->set('code_hash', password_hash($code, PASSWORD_DEFAULT));
        $entry->set('expires', DateTime::now()->addMinutes(self::MINUTES));
        $entry->set('attempts', 0);
        $resets->saveOrFail($entry);

        if (!$this->mail->send('passwordResetCode', [(string)$admin->admin_email, $code, self::MINUTES])) {
            Log::error('The password reset code for account #' . $admin->admin_id . ' could not be emailed.');
        }
    }

    /**
     * Check the code and, if it is right, set the new password.
     *
     * @param string $email The account's address.
     * @param string $code The six digit code.
     * @param string $password The new password.
     * @param string $confirm The new password again.
     * @param string $ip The address the request came from, for the audit trail.
     * @return array{status: string, admin: \App\Model\Entity\Admin|null}
     */
    public function reset(string $email, string $code, string $password, string $confirm, string $ip): array
    {
        $admin = $this->findAccount($email);
        $resets = $this->fetchTable('PasswordResets');
        $open = $admin === null ? null : $resets->find()
            ->where([
                'admin_id' => $admin->admin_id,
                'used IS' => null,
                'expires >' => DateTime::now(),
                'attempts <' => self::MAX_ATTEMPTS,
            ])
            ->orderByDesc('id')
            ->first();

        if ($admin === null || $open === null) {
            $this->spendTheSameEffort();

            return ['status' => self::INVALID, 'admin' => null];
        }

        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $open->code_hash)) {
            $open->set('attempts', $open->attempts + 1);
            if ($open->attempts >= self::MAX_ATTEMPTS) {
                $open->set('used', DateTime::now());
            }
            $resets->saveOrFail($open);

            return ['status' => self::INVALID, 'admin' => null];
        }

        // The code is right. A weak password must not cost the person their code.
        $accounts = $this->fetchTable('Admins');
        $admin = $accounts->patchEntity($admin, [
            'admin_password' => $password,
            'confirm_password' => $confirm,
        ]);
        if ($password === '') {
            $admin->setError('admin_password', [StrongPassword::REQUIRED]);
        }
        if ($admin->hasErrors() || !$accounts->save($admin)) {
            return ['status' => self::PASSWORD, 'admin' => $admin];
        }

        $this->cancelOpenCodes((int)$admin->admin_id);
        $this->fetchTable('AuditLogs')->record(
            'admin.password_reset',
            (int)$admin->admin_id,
            'admin',
            (string)$admin->admin_id,
            [],
            $ip,
        );

        return ['status' => self::OK, 'admin' => $admin];
    }

    /**
     * The active office account with this email, whatever the case or spacing.
     *
     * @param string $email What was typed.
     * @return \App\Model\Entity\Admin|null
     */
    private function findAccount(string $email): ?Admin
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        /** @var \App\Model\Entity\Admin|null */
        return $this->fetchTable('Admins')->find()
            ->where(['admin_email' => $email, 'active' => true])
            ->first();
    }

    /**
     * Do about as much work as a real account would cost, so an unknown address is not quicker.
     *
     * @return void
     */
    private function spendTheSameEffort(): void
    {
        password_hash('no such account', PASSWORD_DEFAULT);
    }

    /**
     * Make every unused code for this account unusable.
     *
     * @param int $adminId The account.
     * @return void
     */
    private function cancelOpenCodes(int $adminId): void
    {
        $this->fetchTable('PasswordResets')->updateAll(
            ['used' => DateTime::now()],
            ['admin_id' => $adminId, 'used IS' => null],
        );
    }
}
