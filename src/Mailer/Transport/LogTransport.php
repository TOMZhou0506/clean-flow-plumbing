<?php
declare(strict_types=1);

namespace App\Mailer\Transport;

use Cake\Log\Log;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;

/**
 * Writes an email to the log instead of sending it.
 *
 * This is the default transport, so a local machine can run every email feature
 * without a mail server and without anything being delivered by accident. The
 * server switches to SMTP in its own config/app_local.php.
 *
 * The message is written to logs/email.log (cli-email.log from the command line), headers first and then the body.
 */
class LogTransport extends AbstractTransport
{
    /**
     * @inheritDoc
     */
    public function send(Message $message): array
    {
        $headers = $message->getHeadersString(
            ['from', 'sender', 'replyTo', 'readReceipt', 'returnPath', 'to', 'cc', 'subject'],
        );
        $body = implode("\r\n", $message->getBody());

        Log::info("Email written to the log, not sent:\r\n" . $headers . "\r\n\r\n" . $body, 'email');

        return ['headers' => $headers, 'message' => $body];
    }
}
