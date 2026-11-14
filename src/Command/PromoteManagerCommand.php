<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Admin;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * One-time, explicit bootstrap. No manager can be created through the site.
 */
class PromoteManagerCommand extends Command
{
    /** @inheritDoc */
    public static function defaultName(): string
    {
        return 'promote_manager';
    }

    /** @inheritDoc */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Promote one existing active admin to the sole manager account.')
            ->addArgument('email', ['required' => true, 'help' => 'Exact sign-in email of the existing admin']);
    }

    /** @inheritDoc */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $admins = $this->fetchTable('Admins');
        if ($admins->find()->where(['role' => Admin::ROLE_MANAGER])->count() !== 0) {
            $io->err('A manager account already exists. No change was made.');

            return static::CODE_ERROR;
        }

        $email = mb_strtolower(trim((string)$args->getArgument('email')));
        $target = $admins->find()
            ->where(['admin_email' => $email, 'role' => Admin::ROLE_ADMIN, 'active' => true])
            ->first();
        if ($target === null) {
            $io->err('No active admin with that exact email was found. No change was made.');

            return static::CODE_ERROR;
        }

        $admins->changeRole((int)$target->admin_id, Admin::ROLE_MANAGER);
        $io->success('Manager account created from admin #' . $target->admin_id . '. Sign out and back in.');

        return static::CODE_SUCCESS;
    }
}
