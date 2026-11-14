<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\LoginRateLimitsTable;
use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds shared login counter identities and applies the configured limits.
 */
class LoginRateLimiter
{
    private const SCOPE_IP = 'ip';
    private const SCOPE_ACCOUNT_IP = 'account_ip';

    /**
     * Every sign-in path counts against the same address, whichever page it came from.
     * There is no role on the login form any more, so the old pages share it too.
     */
    private const SHARED_ROLE = 'login';

    /**
     * @var \App\Model\Table\LoginRateLimitsTable
     */
    private LoginRateLimitsTable $counters;

    /**
     * @param \App\Model\Table\LoginRateLimitsTable|null $counters Counter table.
     */
    public function __construct(?LoginRateLimitsTable $counters = null)
    {
        /** @var \App\Model\Table\LoginRateLimitsTable $table */
        $table = $counters ?? FactoryLocator::get('Table')->get('LoginRateLimits');
        $this->counters = $table;
    }

    /**
     * Whether login throttling is enabled for this environment.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool)Configure::read('LoginRateLimit.enabled', true);
    }

    /**
     * Reserve capacity for one login POST before password verification.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @return array{allowed: bool, retryAfter: int}|null Null for non-login requests.
     */
    public function reserve(ServerRequestInterface $request): ?array
    {
        $context = $this->context($request);
        if ($context === null) {
            return null;
        }

        $ipResult = $this->counters->consume(
            $this->fingerprint([self::SCOPE_IP, $context['ip']]),
            self::SCOPE_IP,
            (int)Configure::read('LoginRateLimit.ip.limit', 30),
            (int)Configure::read('LoginRateLimit.ip.window', 60),
        );
        if (!$ipResult['allowed']) {
            return ['allowed' => false, 'retryAfter' => $ipResult['retryAfter']];
        }

        $accountResult = $this->counters->consume(
            $this->accountFingerprint($context),
            self::SCOPE_ACCOUNT_IP,
            (int)Configure::read('LoginRateLimit.accountIp.limit', 5),
            (int)Configure::read('LoginRateLimit.accountIp.window', 900),
        );

        // Approximately one per cent of login requests performs housekeeping,
        // avoiding a permanent build-up without requiring cPanel cron access.
        if (hexdec(substr($this->accountFingerprint($context), 0, 2)) < 3) {
            $this->counters->clearExpired();
        }

        return [
            'allowed' => $accountResult['allowed'],
            'retryAfter' => $accountResult['retryAfter'],
        ];
    }

    /**
     * Clear the account-and-IP counter after successful authentication.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @return void
     */
    public function clearSuccessfulAttempt(ServerRequestInterface $request): void
    {
        $context = $this->context($request);
        if ($context !== null) {
            $this->counters->clear($this->accountFingerprint($context));
        }
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @return array{ip: string, role: string, account: string}|null
     */
    private function context(ServerRequestInterface $request): ?array
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return null;
        }

        $params = (array)$request->getAttribute('params', []);
        $controller = strtolower((string)($params['controller'] ?? ''));
        $action = strtolower((string)($params['action'] ?? ''));
        $isReset = $controller === 'passwordresets' && in_array($action, ['request', 'reset'], true);
        if ($action !== 'login' && !($controller === 'login' && $action === 'index') && !$isReset) {
            return null;
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if ($controller === 'login' && $action === 'index') {
            // One page for every role now, so there is no role to count against.
            $role = self::SHARED_ROLE;
            $account = $body['email'] ?? '';
        } elseif ($isReset) {
            // Asking for a code and guessing one are limited the same way as signing in: per
            // address and per source, so neither can be used to flood a mailbox or guess a code.
            $role = 'reset_' . $action;
            $account = $body['email'] ?? '';
        } elseif ($controller === 'admins' && $action === 'login') {
            $role = self::SHARED_ROLE;
            $account = $body['admin_email'] ?? '';
        } elseif ($controller === 'customers' && $action === 'login') {
            $role = self::SHARED_ROLE;
            $account = $body['cust_email'] ?? '';
        } else {
            return null;
        }

        return [
            'ip' => $this->connectingIp($request),
            'role' => $role,
            'account' => mb_strtolower(trim(is_string($account) ? $account : '')),
        ];
    }

    /**
     * Use the address supplied by the web server, not a spoofable forwarded header.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @return string
     */
    private function connectingIp(ServerRequestInterface $request): string
    {
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }

    /**
     * @param array{ip: string, role: string, account: string} $context Login dimensions.
     * @return string
     */
    private function accountFingerprint(array $context): string
    {
        return $this->fingerprint([
            self::SCOPE_ACCOUNT_IP,
            $context['ip'],
            $context['role'],
            $context['account'],
        ]);
    }

    /**
     * @param list<string> $parts Counter dimensions.
     * @return string
     */
    private function fingerprint(array $parts): string
    {
        $secret = (string)Configure::read('LoginRateLimit.keySalt', '');
        if ($secret === '') {
            $secret = (string)Configure::read('Security.salt', '');
        }

        return hash_hmac('sha256', implode("\0", $parts), $secret);
    }
}
