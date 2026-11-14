<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\FormRateLimitsTable;
use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Identifies protected form submissions and consumes their allowance.
 */
class FormRateLimiter
{
    private const SCOPE_CONTACT_IP = 'contact_ip';
    private const SCOPE_JOB_CUSTOMER_IP = 'job_customer_ip';

    private FormRateLimitsTable $counters;

    /**
     * @param \App\Model\Table\FormRateLimitsTable|null $counters Counter table.
     */
    public function __construct(?FormRateLimitsTable $counters = null)
    {
        /** @var \App\Model\Table\FormRateLimitsTable $table */
        $table = $counters ?? FactoryLocator::get('Table')->get('FormRateLimits');
        $this->counters = $table;
    }

    /**
     * Whether form submission throttling is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool)Configure::read('FormRateLimit.enabled', true);
    }

    /**
     * @return array{allowed: bool, retryAfter: int, form: string}|null
     */
    public function reserve(ServerRequestInterface $request): ?array
    {
        $context = $this->context($request);
        if ($context === null) {
            return null;
        }

        $configKey = $context['form'] === 'contact' ? 'contact' : 'jobRequest';
        $result = $this->counters->consume(
            $this->fingerprint($context['dimensions']),
            $context['scope'],
            (int)Configure::read("FormRateLimit.$configKey.limit", 5),
            (int)Configure::read("FormRateLimit.$configKey.window", 900),
        );

        // Perform lightweight probabilistic housekeeping without needing cron.
        if (hexdec(substr($this->fingerprint($context['dimensions']), 0, 2)) < 3) {
            $this->counters->clearExpired();
        }

        return [
            'allowed' => $result['allowed'],
            'retryAfter' => $result['retryAfter'],
            'form' => $context['form'],
        ];
    }

    /**
     * @return array{form: string, scope: string, dimensions: list<string>}|null
     */
    private function context(ServerRequestInterface $request): ?array
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return null;
        }

        $params = (array)$request->getAttribute('params', []);
        $controller = strtolower((string)($params['controller'] ?? ''));
        $action = strtolower((string)($params['action'] ?? ''));
        $ip = $this->connectingIp($request);

        if ($controller === 'contacts' && $action === 'add') {
            return [
                'form' => 'contact',
                'scope' => self::SCOPE_CONTACT_IP,
                'dimensions' => [self::SCOPE_CONTACT_IP, $ip],
            ];
        }

        if ($controller !== 'jobrequests' || $action !== 'add') {
            return null;
        }

        $identity = $request->getAttribute('identity');
        $customerId = $identity?->get('cust_id');
        if (!is_int($customerId) && !ctype_digit((string)$customerId)) {
            return null;
        }

        return [
            'form' => 'jobRequest',
            'scope' => self::SCOPE_JOB_CUSTOMER_IP,
            'dimensions' => [self::SCOPE_JOB_CUSTOMER_IP, (string)$customerId, $ip],
        ];
    }

    /**
     * Read the connecting address supplied by the web server.
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
     * @param list<string> $parts Counter dimensions.
     */
    private function fingerprint(array $parts): string
    {
        $secret = (string)Configure::read('FormRateLimit.keySalt', '');
        if ($secret === '') {
            $secret = (string)Configure::read('Security.salt', '');
        }

        return hash_hmac('sha256', implode("\0", $parts), $secret);
    }
}
