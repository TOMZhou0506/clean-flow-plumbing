<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Database\Driver\Mysql;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * Persistent counters shared by every PHP worker handling form submissions.
 */
class FormRateLimitsTable extends Table
{
    /**
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('form_rate_limits');
        $this->setPrimaryKey('rate_key');
        $this->addBehavior('Timestamp');
    }

    /**
     * Consume one submission in a fixed time window.
     *
     * @return array{allowed: bool, retryAfter: int, remaining: int}
     */
    public function consume(string $rateKey, string $scope, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);

        return $this->getConnection()->transactional(
            function () use ($rateKey, $scope, $limit, $windowSeconds): array {
                $now = DateTime::now();
                $query = $this->find()->where(['rate_key' => $rateKey]);

                if ($this->getConnection()->getDriver() instanceof Mysql) {
                    $query->epilog('FOR UPDATE');
                }

                $counter = $query->first();
                if ($counter === null || $counter->expires_at <= $now) {
                    if ($counter === null) {
                        $counter = $this->newEmptyEntity();
                        $counter->set('rate_key', $rateKey);
                    }

                    $counter->set('scope', $scope);
                    $counter->set('attempts', 1);
                    $counter->set('expires_at', $now->addSeconds($windowSeconds));
                    $this->saveOrFail($counter);

                    return ['allowed' => true, 'retryAfter' => 0, 'remaining' => $limit - 1];
                }

                $attempts = (int)$counter->attempts;
                $retryAfter = max(1, $counter->expires_at->getTimestamp() - $now->getTimestamp());
                if ($attempts >= $limit) {
                    return ['allowed' => false, 'retryAfter' => $retryAfter, 'remaining' => 0];
                }

                $counter->set('attempts', $attempts + 1);
                $this->saveOrFail($counter);

                return [
                    'allowed' => true,
                    'retryAfter' => 0,
                    'remaining' => $limit - $attempts - 1,
                ];
            },
        );
    }

    /**
     * Remove counters whose fixed windows have ended.
     *
     * @return void
     */
    public function clearExpired(): void
    {
        $this->deleteAll(['expires_at <=' => DateTime::now()]);
    }
}
