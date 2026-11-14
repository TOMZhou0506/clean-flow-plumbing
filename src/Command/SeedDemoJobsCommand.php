<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Admin;
use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\JobRequest;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fills a development database with believable plumber jobs, so the plumber
 * dashboard and the office job pages are not empty while you work on them.
 *
 *     bin/cake seed_demo_jobs                 every active plumber
 *     bin/cake seed_demo_jobs --plumber Tom   just that plumber's username
 *     bin/cake seed_demo_jobs --remove        delete everything this created
 *
 * Everything it creates hangs off a handful of fake customers whose emails
 * start with demo.customer and end with @example.com, which is how --remove
 * finds it again, so it never touches real data. It refuses to run outside
 * debug mode so it cannot be pointed at a live site by accident.
 */
class SeedDemoJobsCommand extends Command
{
    private const DEMO_EMAIL_LIKE = 'demo.customer%@example.com';

    /** @var list<array{string, string, string, string}> Street, suburb, postcode, description. */
    private const JOBS = [
        ['14 Elm Street', 'Clayton', '3168', 'Leaking hot water system in the laundry.'],
        ['3 Park Avenue', 'Oakleigh', '3166', 'Blocked kitchen drain, water backing up.'],
        ['88 High Road', 'Glen Waverley', '3150', 'Replace two bathroom taps.'],
        ['21 River Walk', 'Mulgrave', '3170', 'Annual maintenance check on pipes.'],
        ['5 Station Lane', 'Clayton', '3168', 'Burst pipe under the kitchen sink.'],
        ['60 Wattle Drive', 'Springvale', '3171', 'Shower leak behind the bathroom wall.'],
        ['12 Banksia Court', 'Notting Hill', '3168', 'Toilet cistern keeps running.'],
        ['9 Quarry Road', 'Mount Waverley', '3149', 'Install a new dishwasher connection.'],
        ['47 Heath Street', 'Hughesdale', '3166', 'Low water pressure in the whole house.'],
        ['2 Lyrebird Place', 'Wheelers Hill', '3150', 'Outdoor tap leaking at the base.'],
        ['76 Corrigan Road', 'Noble Park', '3174', 'Gas hot water unit not heating.'],
        ['31 Federation Way', 'Dandenong', '3175', 'Blocked bathroom basin.'],
        ['18 Orchard Grove', 'Murrumbeena', '3163', 'Dripping tap in the ensuite.'],
        ['55 Jasper Road', 'Bentleigh', '3204', 'Pipe noise (banging) when taps are turned off.'],
        ['4 Kookaburra Lane', 'Chadstone', '3148', 'Replace old laundry trough taps.'],
        ['27 Milton Street', 'Ashwood', '3147', 'Stormwater drain overflowing after rain.'],
    ];

    /** @var list<array{string, string}> First and last name for the fake customers. */
    private const CUSTOMERS = [
        ['Priya', 'Nair'], ['Marcus', 'Bell'], ['Hannah', 'Cho'],
        ['Daniel', 'Okafor'], ['Sophie', 'Marsh'], ['Liam', 'Tran'],
    ];

    /** @inheritDoc */
    public static function defaultName(): string
    {
        return 'seed_demo_jobs';
    }

    /** @inheritDoc */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Create (or with --remove, delete) fake plumber jobs for development.')
            ->addOption('plumber', [
                'help' => 'Only seed this plumber (exact admin username). Default: every active plumber.',
            ])
            ->addOption('remove', [
                'boolean' => true,
                'help' => 'Delete all demo jobs, assignments, checks and customers instead.',
            ]);
    }

    /** @inheritDoc */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        if (!Configure::read('debug')) {
            $io->err('Refusing to run with debug off. This command is for development databases only.');

            return static::CODE_ERROR;
        }

        if ($args->getOption('remove')) {
            return $this->remove($io);
        }

        $customers = $this->fetchTable('Customers');
        if ($customers->find()->where(['cust_email LIKE' => self::DEMO_EMAIL_LIKE])->count() > 0) {
            $io->err('Demo data is already there. Run with --remove first if you want a fresh set.');

            return static::CODE_ERROR;
        }

        $admins = $this->fetchTable('Admins');
        $plumberQuery = $admins->find()->where(['role' => Admin::ROLE_STAFF, 'active' => true])
            ->orderBy(['admin_id' => 'ASC']);
        $only = $args->getOption('plumber');
        if (is_string($only) && $only !== '') {
            $plumberQuery->where(['admin_username' => $only]);
        }
        $plumbers = $plumberQuery->all()->toList();
        if ($plumbers === []) {
            $io->err('No active plumber found' . ($only ? ' called "' . $only . '"' : '') . '.');

            return static::CODE_ERROR;
        }

        $officeAdmin = $admins->find()->where(['role' => Admin::ROLE_ADMIN, 'active' => true])
            ->orderBy(['admin_id' => 'ASC'])->first();
        $adminId = $officeAdmin !== null ? (int)$officeAdmin->admin_id : (int)$plumbers[0]->admin_id;

        $vehicleIds = $this->fetchTable('Vehicles')->find()->where(['active' => true])
            ->orderBy(['id' => 'ASC'])->all()->extract('id')->toList();
        $demoCustomers = $this->createCustomers();

        $created = 0;
        foreach ($plumbers as $plumberIndex => $plumber) {
            foreach ($this->scheduleFor() as $slotIndex => $slot) {
                // A job that is in progress holds its vehicle until the plumber
                // finishes it, whatever time is being booked. If every vehicle
                // had one, nothing could be assigned until the demo jobs were
                // finished, so when there are several plumbers the last
                // vehicle is left free. The first plumber always keeps theirs.
                if (
                    $slot[0] === JobRequest::STATUS_IN_PROGRESS
                    && $vehicleIds !== []
                    && $plumberIndex > 0
                    && $plumberIndex >= count($vehicleIds) - 1
                ) {
                    continue;
                }

                $listIndex = ($plumberIndex * 5 + $slotIndex) % count(self::JOBS);
                $this->createJob(
                    self::JOBS[$listIndex],
                    $demoCustomers[($plumberIndex + $slotIndex) % count($demoCustomers)],
                    (int)$plumber->admin_id,
                    $adminId,
                    $vehicleIds === [] ? null : (int)$vehicleIds[$plumberIndex % count($vehicleIds)],
                    $slot,
                    ($plumberIndex * 7 + $slotIndex) % 100,
                );
                $created++;
            }
            $io->out('Seeded jobs for ' . $plumber->admin_username . '.');
        }

        $io->success('Created ' . $created . ' demo jobs. Undo with: bin/cake seed_demo_jobs --remove');

        return static::CODE_SUCCESS;
    }

    /**
     * The mix of jobs each plumber gets, as offsets from today.
     *
     * Each entry is [status, working-day offset, start hour, duration hours].
     * Today's two jobs are placed relative to the current time instead (see
     * createJob()), so they really are "today" whenever this is run.
     *
     * @return list<array{string, int, float, float}>
     */
    private function scheduleFor(): array
    {
        return [
            [JobRequest::STATUS_IN_PROGRESS, 0, 0.0, 2.0],
            [JobRequest::STATUS_ASSIGNED, 0, 3.0, 1.0],
            [JobRequest::STATUS_ASSIGNED, 1, 9.0, 1.5],
            [JobRequest::STATUS_ASSIGNED, 2, 11.0, 1.0],
            [JobRequest::STATUS_ASSIGNED, 4, 13.5, 2.0],
            [JobRequest::STATUS_COMPLETED, -1, 9.0, 1.5],
            [JobRequest::STATUS_COMPLETED, -2, 13.0, 1.0],
            [JobRequest::STATUS_COMPLETED, -4, 10.0, 2.0],
            [JobRequest::STATUS_UNABLE_TO_COMPLETE, -6, 14.0, 1.0],
        ];
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function createCustomers(): array
    {
        $customers = $this->fetchTable('Customers');
        $created = [];
        foreach (self::CUSTOMERS as $index => [$first, $last]) {
            $customer = $customers->newEntity([
                'cust_email' => 'demo.customer' . ($index + 1) . '@example.com',
                'cust_fname' => $first,
                'cust_lname' => $last,
                'cust_phone' => '04' . sprintf('%02d %03d %03d', 10 + $index, 200 + $index, 300 + $index),
                'cust_address' => 'Demo data - not a real customer',
                // Nobody can sign in as a demo customer: the password is random
                // and thrown away.
                'cust_password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            ], ['accessibleFields' => ['*' => true], 'validate' => false]);
            $created[] = $customers->saveOrFail($customer, ['checkRules' => false]);
        }

        return $created;
    }

    /**
     * @param array{string, string, string, string} $job Street, suburb, postcode, description.
     * @param \Cake\Datasource\EntityInterface $customer Who asked for it.
     * @param int $plumberId The plumber it is assigned to.
     * @param int $adminId The office admin who "assigned" it.
     * @param int|null $vehicleId A vehicle, if the system has any.
     * @param array{string, int, float, float} $slot Status, day offset, start hour, duration.
     * @param int $variant Varies the phone number and notes between jobs.
     * @return void
     */
    private function createJob(
        array $job,
        EntityInterface $customer,
        int $plumberId,
        int $adminId,
        ?int $vehicleId,
        array $slot,
        int $variant,
    ): void {
        [$status, $dayOffset, $hour, $hours] = $slot;
        $melbourne = new DateTimeZone('Australia/Melbourne');
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $melbourne);

        if ($dayOffset === 0) {
            // Today's jobs are placed against the clock so they really are
            // today: the one in progress began an hour ago, the next one is a
            // few hours away.
            $startLocal = $status === JobRequest::STATUS_IN_PROGRESS
                ? max($now->modify('-1 hour'), $now->setTime(0, 5))
                : min($now->modify('+' . (int)$hour . ' hours'), $now->setTime(23, 0));
        } else {
            $day = $this->workingDay($now, $dayOffset);
            $startLocal = $day->setTime((int)$hour, (int)(($hour - (int)$hour) * 60));
        }
        $endLocal = $startLocal->modify('+' . (int)($hours * 60) . ' minutes');

        $start = $startLocal->setTimezone($utc);
        $end = $endLocal->setTimezone($utc);
        $fmt = static fn(DateTimeImmutable $date): string => $date->format('Y-m-d H:i:s');
        $created = $start->modify('-6 days');

        $jobs = $this->fetchTable('JobRequests');
        $request = $jobs->newEntity([
            'customer_id' => $customer->cust_id,
            'street_address' => $job[0],
            'suburb' => $job[1],
            'state' => 'VIC',
            'postcode' => $job[2],
            'job_description' => $job[3],
            'preferred_datetime' => $fmt($start),
            'contact_phone' => '04' . sprintf('%02d %03d %03d', 20 + $variant % 70, 100 + $variant, 400 + $variant),
            'access_instructions' => $variant % 3 === 0 ? 'Gate code is 4821. Please knock loudly.' : null,
            'status' => $status,
            'read_at' => $fmt($created->modify('+2 hours')),
            'read_by' => $adminId,
            'created' => $fmt($created),
            'modified' => $fmt($created),
        ], ['accessibleFields' => ['*' => true], 'validate' => false]);
        $request = $jobs->saveOrFail($request, ['checkRules' => false]);

        $checks = $this->fetchTable('ComplianceChecks');
        foreach (array_keys(ComplianceCheck::REQUIREMENTS) as $requirement) {
            $checks->record(
                (int)$request->id,
                $requirement,
                true,
                null,
                $adminId,
                ComplianceCheck::SUBJECT_JOB_REQUEST,
            );
        }

        $assignments = $this->fetchTable('JobAssignments');
        $assignment = $assignments->newEmptyEntity();
        $assignment->set('job_request_id', $request->id);
        $assignment->set('plumber_admin_id', $plumberId);
        $assignment->set('assigned_by', $adminId);
        $assignment->set('vehicle_id', $vehicleId);
        $assignment->set('scheduled_start', $fmt($start));
        $assignment->set('scheduled_end', $fmt($end));

        if ($status !== JobRequest::STATUS_ASSIGNED) {
            $assignment->set('started_at', $fmt($start->modify('+5 minutes')));
        }
        if ($status === JobRequest::STATUS_COMPLETED || $status === JobRequest::STATUS_UNABLE_TO_COMPLETE) {
            $assignment->set('finished_at', $fmt($end->modify('-10 minutes')));
        }
        if ($status === JobRequest::STATUS_UNABLE_TO_COMPLETE) {
            $assignment->set('unable_reason', 'Could not reach the pipework without a builder opening the wall first.');
        }
        $assignments->saveOrFail($assignment);
    }

    /**
     * The date that many working days from today, skipping weekends.
     *
     * @param \DateTimeImmutable $from The starting day.
     * @param int $offset Working days forward (positive) or back (negative).
     * @return \DateTimeImmutable
     */
    private function workingDay(DateTimeImmutable $from, int $offset): DateTimeImmutable
    {
        $step = $offset < 0 ? '-1 day' : '+1 day';
        $day = $from;
        for ($remaining = abs($offset); $remaining > 0;) {
            $day = $day->modify($step);
            if ((int)$day->format('N') <= 5) {
                $remaining--;
            }
        }

        return $day;
    }

    /**
     * Deletes everything the seed created, and nothing else.
     *
     * @param \Cake\Console\ConsoleIo $io Console output.
     * @return int
     */
    private function remove(ConsoleIo $io): int
    {
        $customers = $this->fetchTable('Customers');
        $demoIds = $customers->find()->where(['cust_email LIKE' => self::DEMO_EMAIL_LIKE])
            ->all()->extract('cust_id')->toList();
        if ($demoIds === []) {
            $io->out('No demo data found. Nothing to remove.');

            return static::CODE_SUCCESS;
        }

        $jobs = $this->fetchTable('JobRequests');
        $jobIds = $jobs->find()->where(['customer_id IN' => $demoIds])->all()->extract('id')->toList();

        if ($jobIds !== []) {
            $this->fetchTable('ComplianceChecks')->deleteAll([
                'subject_type' => ComplianceCheck::SUBJECT_JOB_REQUEST,
                'submission_id IN' => $jobIds,
            ]);
            $this->fetchTable('JobAssignments')->deleteAll(['job_request_id IN' => $jobIds]);
            $jobs->deleteAll(['id IN' => $jobIds]);
        }
        $customers->deleteAll(['cust_id IN' => $demoIds]);

        $io->success('Removed ' . count($jobIds) . ' demo jobs and ' . count($demoIds) . ' demo customers.');

        return static::CODE_SUCCESS;
    }
}
