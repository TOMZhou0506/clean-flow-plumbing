<?php
declare(strict_types=1);

namespace App\Service;

use App\Mailer\SiteMailer;
use Cake\Log\Log;
use Throwable;

/**
 * Sends a site email without ever breaking the page that asked for it.
 *
 * A mail server that is down, a wrong password or a refused recipient all throw.
 * Left alone, that shows the visitor an error page. This catches it, writes the
 * reason to the log, and returns false so the caller can show an ordinary
 * message such as "we could not send the email, please try again".
 */
class MailDelivery
{
    /**
     * @param string $action A method on SiteMailer.
     * @param array<mixed> $args The arguments for that method.
     * @return bool True when the message was handed to the mail transport.
     */
    public function send(string $action, array $args = []): bool
    {
        try {
            (new SiteMailer())->send($action, $args);

            return true;
        } catch (Throwable $e) {
            // The reason goes to the log; the address and message body do not.
            Log::error(sprintf('Email "%s" could not be sent: %s: %s', $action, $e::class, $e->getMessage()));

            return false;
        }
    }
}
