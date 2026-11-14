<?php
declare(strict_types=1);

namespace App\Service\Address;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Log\Log;
use Throwable;

/**
 * Talks to getAddress(), which serves Australian addresses out of G-NAF.
 *
 * Two calls make up one address. /autocomplete suggests as the customer types
 * and is free, and /get turns the one they picked into the full record and is
 * the only call charged against the plan. That split is why this class caches
 * resolved records hard and suggestions lightly: a repeated suggestion costs
 * nothing, a repeated resolve costs one of the two hundred look-ups a day the
 * free plan allows.
 *
 * The key is read from Configure, which reads it from config/app_local.php.
 * That file is gitignored, so the key never reaches the remote, and because
 * every call is made from here the browser never sees it either. getAddress()
 * offers a domain token for front-end use instead, which is published in the
 * page on purpose, but a token in the page source is a token anybody can lift
 * and spend our daily allowance with, so I kept the whole exchange on the
 * server.
 *
 * Nothing here throws. A missing key, a refused connection, a timeout, a 429,
 * a response whose shape has changed: all of them end as an empty list or a
 * null, the suggestions quietly stop appearing, and the customer types the
 * address themselves exactly as they do today.
 */
class GetAddressClient implements AddressLookupInterface
{
    /**
     * Short, because somebody is typing. A suggestion that lands after they
     * have finished the line is worse than no suggestion, and a slow provider
     * should not be holding a PHP worker open while they wait.
     *
     * @var int
     */
    private const TIMEOUT_SECONDS = 4;

    /**
     * Below this there is no point asking. Two or three characters match half
     * the state, and every one of those requests counts against the per-window
     * rate limit on our account for no benefit.
     *
     * @var int
     */
    private const MIN_TERM_LENGTH = 4;

    /**
     * The provider caps this at six anyway.
     *
     * @var int
     */
    private const MAX_SUGGESTIONS = 6;

    /**
     * Two cache configs rather than one, because the two calls want very
     * different lifetimes and a FileEngine's duration is set per config, not
     * per write.
     *
     * Suggestions expire in an hour: they cost nothing to fetch again, and a
     * cached empty list for a term that now matches would be invisible and
     * annoying. Resolved addresses are held for a month: the licence allows
     * storing them, the record only changes with a quarterly G-NAF release,
     * and every cache hit is a look-up not spent out of the two hundred a day
     * the free plan allows.
     *
     * @var string
     */
    private const SUGGESTION_CACHE = 'address_suggestions';

    /**
     * @var string
     */
    private const RESOLVED_CACHE = 'address_resolved';

    /**
     * G-NAF identifiers look like GAVIC419734226. Anything else never leaves
     * the application: the id arrives from the browser and is pasted into a
     * URL path, so this is the check that stops a crafted value walking out of
     * /get and into another endpoint.
     *
     * @var string
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_]{1,40}$/';

    /**
     * @var \Cake\Http\Client
     */
    private Client $http;

    /**
     * @param \Cake\Http\Client|null $http Injectable for tests.
     */
    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => self::TIMEOUT_SECONDS]);
    }

    /**
     * @inheritDoc
     */
    public function suggest(string $term): array
    {
        $term = trim($term);
        $key = $this->apiKey();

        if ($key === '' || mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return [];
        }

        $cacheKey = 'suggest_' . sha1(mb_strtolower($term));
        $cached = $this->cached($cacheKey, self::SUGGESTION_CACHE);
        if (is_array($cached)) {
            /** @var list<array{id: string, label: string}> $cached */
            return $cached;
        }

        $body = ['top' => self::MAX_SUGGESTIONS];

        // The state filter is the service area rule, enforced before the
        // customer ever sees a choice. Clean Flow works in Melbourne and
        // regional Victoria, so suggesting a Sydney address would only invite
        // a job request nobody can take. It is a soft rule: the field stays
        // typeable, so an interstate address can still be entered by hand and
        // the office can decide what to do with it.
        $state = strtoupper((string)Configure::read('Address.state', ''));
        if ($state !== '') {
            $body['filter'] = ['state' => $state];
        }

        $response = $this->call(
            'POST',
            '/autocomplete/' . rawurlencode($term),
            $body,
        );

        $suggestions = [];
        foreach ((array)($response['suggestions'] ?? []) as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }

            $id = is_scalar($suggestion['id'] ?? null) ? (string)$suggestion['id'] : '';
            $label = is_scalar($suggestion['address'] ?? null) ? (string)$suggestion['address'] : '';

            if ($id === '' || $label === '' || !preg_match(self::ID_PATTERN, $id)) {
                continue;
            }

            $suggestions[] = ['id' => $id, 'label' => $label];
        }

        // Only a successful call is cached. Caching the empty list that a
        // timeout produces would turn a few seconds of provider trouble into
        // an hour of a street that cannot be found.
        if ($response !== null) {
            $this->remember($cacheKey, $suggestions, self::SUGGESTION_CACHE);
        }

        return $suggestions;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $id): ?array
    {
        $key = $this->apiKey();

        if ($key === '' || !preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        $cacheKey = 'resolve_' . sha1($id);
        $cached = $this->cached($cacheKey, self::RESOLVED_CACHE);
        if (is_array($cached)) {
            /** @var array{street: string, suburb: string, state: string, postcode: string} $cached */
            return $cached;
        }

        $record = $this->call('GET', '/get/' . rawurlencode($id));
        if ($record === null) {
            return null;
        }

        $fields = GnafAddress::fromRecord($record);
        if ($fields === null) {
            return null;
        }

        // A second check of the service area, on the field that actually gets
        // saved. The filter above shapes what is offered, but the id arrives
        // from the browser and nothing stops somebody posting one they found
        // elsewhere.
        $state = strtoupper((string)Configure::read('Address.state', ''));
        if ($state !== '' && $fields['state'] !== $state) {
            return null;
        }

        $this->remember($cacheKey, $fields, self::RESOLVED_CACHE);

        return $fields;
    }

    /**
     * One request, with every failure flattened to null.
     *
     * @param string $method HTTP method.
     * @param string $path Path below the endpoint, already encoded.
     * @param array<string, mixed>|null $body JSON body, for POST.
     * @return array<string, mixed>|null Decoded body, or null when the call did not succeed.
     */
    private function call(string $method, string $path, ?array $body = null): ?array
    {
        $base = rtrim((string)Configure::read('Address.endpoint', 'https://api.getaddress.io'), '/');

        // The key goes in the query string because that is the only place
        // getAddress() reads it from. Their documentation is explicit that a
        // header is not accepted. It is not ideal, so it is worth saying where
        // it does and does not end up: this is a server to server call, so the
        // URL is not in a browser history or a referrer header, and the catch
        // below logs the exception class rather than the request.
        $url = $base . $path . '?api-key=' . rawurlencode($this->apiKey());

        try {
            $response = $method === 'POST'
                ? $this->http->post($url, (string)json_encode($body ?? []), ['type' => 'json'])
                : $this->http->get($url);
        } catch (Throwable $exception) {
            // The call never left. On shared hosting this is the one to expect:
            // cPanel accounts often block outbound HTTPS, which is exactly how
            // the assistant failed when it was first deployed.
            Log::warning('Address lookup could not reach the provider: ' . $exception::class);

            return null;
        }

        if (!$response->isOk()) {
            // 429 is the provider throttling us, not the customer. Worth
            // seeing in the log, because it means our own limits are set too
            // loose or the daily allowance has run out.
            Log::warning('Address lookup returned HTTP ' . $response->getStatusCode());

            return null;
        }

        try {
            $decoded = $response->getJson();
        } catch (Throwable $exception) {
            Log::warning('Address lookup returned a body I could not read: ' . $exception::class);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $key Cache key.
     * @param string $config Cache config to read from.
     * @return mixed The cached value, or null.
     */
    private function cached(string $key, string $config): mixed
    {
        try {
            return Cache::read($key, $this->cacheConfig($config));
        } catch (Throwable $exception) {
            // A cache that cannot be read is a performance problem, not a
            // reason to stop answering.
            return null;
        }
    }

    /**
     * @param string $key Cache key.
     * @param mixed $value Value to store.
     * @param string $config Cache config to write to.
     * @return void
     */
    private function remember(string $key, mixed $value, string $config): void
    {
        try {
            Cache::write($key, $value, $this->cacheConfig($config));
        } catch (Throwable $exception) {
            Log::warning('Address lookup could not write its cache: ' . $exception::class);
        }
    }

    /**
     * Falls back to the default cache when the dedicated config is missing, so
     * a deployment that has pulled the code but not the new app.php still
     * works. It only costs the difference between the two lifetimes.
     *
     * @param string $config Preferred cache config.
     * @return string
     */
    private function cacheConfig(string $config): string
    {
        return Cache::getConfig($config) === null ? 'default' : $config;
    }

    /**
     * @return string
     */
    private function apiKey(): string
    {
        return trim((string)Configure::read('Address.apiKey', ''));
    }
}
