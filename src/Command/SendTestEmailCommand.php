<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\MailDelivery;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Mailer\Mailer;
use Cake\Mailer\Transport\SmtpTransport;
use Cake\Mailer\TransportFactory;

/**
 * Sends one test email, to prove that sending works on this machine.
 *
 * Run it on the server after filling in the mail settings in config/app_local.php.
 * The password is never printed.
 */
class SendTestEmailCommand extends Command
{
    /** @inheritDoc */
    public static function defaultName(): string
    {
        return 'send_test_email';
    }

    /** @inheritDoc */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Send a test email using the configured mail settings.')
            ->addArgument('to', ['required' => true, 'help' => 'The address to send the test to']);
    }

    /** @inheritDoc */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $to = trim((string)$args->getArgument('to'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $io->err('That is not a valid email address. Nothing was sent.');

            return static::CODE_ERROR;
        }

        $transport = (array)TransportFactory::getConfig('default');
        $className = (string)($transport['className'] ?? 'unknown');
        $io->out('Transport: ' . $className);
        if (is_a($className, SmtpTransport::class, true)) {
            $io->out('Server:    ' . ($transport['host'] ?? '') . ':' . ($transport['port'] ?? ''));
        }

        // The profile was consumed out of Configure at start-up, so read it from the mailer.
        $profile = (array)Mailer::getConfig('default');
        $from = (array)($profile['from'] ?? []);
        $names = [];
        foreach ($from as $address => $name) {
            $names[] = $name . ' <' . $address . '>';
        }
        $io->out('From:      ' . implode(', ', $names));

        if (!(new MailDelivery())->send('testMessage', [$to])) {
            $io->err('The email could not be sent. The reason is in the logs folder (cli-error.log).');

            return static::CODE_ERROR;
        }

        $io->success('Handed to the mail server. Check that it reached ' . $to . ' and not the spam folder.');

        return static::CODE_SUCCESS;
    }
}
