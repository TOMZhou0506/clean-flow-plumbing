<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Database\Driver\Mysql;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * Persistent counters shared by all PHP workers and all three login routes.
 *
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \App\Model\Entity\LoginRateLimit>
 * @method \App\Model\Entity\LoginRateLimit[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\LoginRateLimit>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\LoginRateLimit[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\LoginRateLimit> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\LoginRateLimit[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\LoginRateLimit>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\LoginRateLimit[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\LoginRateLimit> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class LoginRateLimitsTable extends Table
{
    /**
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('login_rate_limits');
        $this->setPrimaryKey('rate_key');
        $this->addBehavior('Timestamp');
    }

    /**
     * Consume one place in a fixed time window.
     *
     * MySQL locks the matching indexed row for the duration of the transaction,
     * so simultaneous requests cannot both consume the final available place.
     * SQLite omits FOR UPDATE because it serialises writes itself; this keeps
     * the same method usable by the isolated test database.
     *
     * @param string $rateKey HMAC fingerprint of the counter dimensions.
     * @param string $scope Counter type, for operations and diagnostics.
     * @param int $limit Maximum attempts in this window.
     * @param int $windowSeconds Window duration in seconds.
     * @return array{allowed: bool, retryAfter: int, remaining: int}
     */
    public function consume(string $rateKey, string $scope, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $connection = $this->getConnection();

        return $connection->transactional(function () use ($rateKey, $scope, $limit, $windowSeconds) {
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

                return [
                    'allowed' => true,
                    'retryAfter' => 0,
                    'remaining' => $limit - 1,
                ];
            }

            $attempts = (int)$counter->attempts;
            $retryAfter = max(1, $counter->expires_at->getTimestamp() - $now->getTimestamp());

            if ($attempts >= $limit) {
                return [
                    'allowed' => false,
                    'retryAfter' => $retryAfter,
                    'remaining' => 0,
                ];
            }

            $counter->set('attempts', $attempts + 1);
            $this->saveOrFail($counter);

            return [
                'allowed' => true,
                'retryAfter' => 0,
                'remaining' => $limit - $attempts - 1,
            ];
        });
    }

    /**
     * A successful login clears only its account-and-IP counter.
     *
     * @param string $rateKey Counter fingerprint.
     * @return void
     */
    public function clear(string $rateKey): void
    {
        $this->deleteAll(['rate_key' => $rateKey]);
    }

    /**
     * Remove expired counters without requiring a cPanel cron job.
     *
     * @return void
     */
    public function clearExpired(): void
    {
        $this->deleteAll(['expires_at <=' => DateTime::now()]);
    }
}
