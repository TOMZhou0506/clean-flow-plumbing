<?php
declare(strict_types=1);

namespace App\Mailer;

use Cake\Mailer\Mailer;

/**
 * Every email the site sends comes from here, signed Clean Flow Plumbing.
 *
 * The sender address and name are set once, in the Email.default profile in
 * config/app.php, so no message can go out under a different name.
 */
class SiteMailer extends Mailer
{
    /**
     * A short message used to check that sending works.
     *
     * @param string $to The address to send it to.
     * @return void
     */
    public function testMessage(string $to): void
    {
        $this
            ->setTo($to)
            ->setSubject('Clean Flow Plumbing email test')
            ->setEmailFormat('text')
            ->viewBuilder()
            ->setTemplate('test_message');
    }

    /**
     * The code for resetting an office account's password.
     *
     * @param string $to The account's own address.
     * @param string $code The six digit code.
     * @param int $minutes How long the code works for.
     * @return void
     */
    public function passwordResetCode(string $to, string $code, int $minutes): void
    {
        $this
            ->setTo($to)
            ->setSubject('Your Clean Flow Plumbing password reset code')
            ->setEmailFormat('text')
            ->setViewVars(['code' => $code, 'minutes' => $minutes])
            ->viewBuilder()
            ->setTemplate('password_reset_code');
    }
}
