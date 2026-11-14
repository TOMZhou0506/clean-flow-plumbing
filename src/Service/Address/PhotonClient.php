<?php
declare(strict_types=1);

namespace App\Service\Address;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Log\Log;
use Throwable;

/**
 * The fallback address lookup: Photon (photon.komoot.io), a free search over
 * OpenStreetMap that needs no key and no account.
 *
 * It is used when no getAddress() key is configured, so every copy of the app
 * gets suggestions without anyone signing up for anything. getAddress() is
 * still the better source, being the official G-NAF list, and takes over as
 * soon as a key is added.
 *
 * The job request form normally calls Photon straight from the browser,
 * because a browser keeps its connection open and gets an answer in a few
 * hundred milliseconds, where a fresh connection from PHP takes seconds. This
 * class is the route it falls back to when the browser cannot reach Photon
 * itself, for example on a network that blocks it.
 *
 * Photon has no "fetch one result by id" call, so suggest() stores each
 * result's fields in the cache under an id of our own, and resolve() reads
 * them back. The id the browser sends is only ever a cache key; the fields
 * never come from the browser.
 *
 * Like GetAddressClient, nothing here throws. Any failure ends as an empty
 * list or a null, and the customer types the address by hand.
 */
class PhotonClient implements AddressLookupInterface
{
    /**
     * @var int
     */
    private const TIMEOUT_SECONDS = 4;

    /**
     * @var int
     */
    private const MIN_TERM_LENGTH = 4;

    /**
     * @var int
     */
    private const MAX_SUGGESTIONS = 6;

    /**
     * Photon is asked for more than are shown, because some results are
     * dropped: outside the service area, or missing a postcode.
     *
     * @var int
     */
    private const FETCH_LIMIT = 15;

    /**
     * @var string
     */
    private const SUGGESTION_CACHE = 'address_suggestions';

    /**
     * Where suggest() keeps each result for resolve() to read back.
     *
     * @var string
     */
    private const RESOLVED_CACHE = 'address_resolved';

    /**
     * "ph" and 32 hex characters, so it can never be mistaken for a G-NAF id.
     *
     * @var string
     */
    private const ID_PATTERN = '/^ph[0-9a-f]{32}$/';

    /**
     * Search boxes, as west,south,east,north, that bias results to the area.
     * They are a little wider than the state itself, so results are filtered
     * by state as well.
     *
     * @var array<string, string>
     */
    private const BOUNDS = [
        'VIC' => '140.9,-39.2,150.0,-33.9',
        '' => '112.9,-43.7,153.7,-10.6',
    ];

    /**
     * @var \Cake\Http\Client
     */
    private Client $http;

    /**
     * @param \Cake\Http\Client|null $http Injectable for tests.
     */
    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout' => self::TIMEOUT_SECONDS,
            // Photon's fair-use policy asks callers to identify themselves.
            'headers' => ['User-Agent' => 'CleanFlowPlumbing/1.0 (job request address lookup)'],
        ]);
    }

    /**
     * Whether Photon is the provider: the lookup is on, there is no getAddress()
     * key, and the fallback has not been turned off. The controller and the job
     * request form both ask this, so they always agree.
     */
    public static function isActive(): bool
    {
        return (bool)Configure::read('Address.enabled', true)
            && trim((string)Configure::read('Address.apiKey', '')) === ''
            && Configure::read('Address.fallback', 'photon') === 'photon';
    }

    /**
     * Photon's search address, for the form to call directly from the browser.
     */
    public static function searchUrl(): string
    {
        return rtrim((string)Configure::read('Address.photonEndpoint', 'https://photon.komoot.io'), '/') . '/api/';
    }

    /**
     * The service area's state ("VIC"), or "" for anywhere in Australia.
     */
    public static function state(): string
    {
        return strtoupper((string)Configure::read('Address.state', ''));
    }

    /**
     * The search box for the configured state, as west,south,east,north.
     */
    public static function bounds(): string
    {
        return self::BOUNDS[self::state()] ?? self::BOUNDS[''];
    }

    /**
     * @inheritDoc
     */
    public function suggest(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return [];
        }

        $state = strtoupper((string)Configure::read('Address.state', ''));
        $cacheKey = 'photon_suggest_' . sha1($state . "\0" . mb_strtolower($term));
        $cached = $this->cached($cacheKey, self::SUGGESTION_CACHE);
        if (is_array($cached)) {
            /** @var list<array{id: string, label: string}> $cached */
            return $cached;
        }

        $query = http_build_query([
            'q' => $term,
            'limit' => self::FETCH_LIMIT,
            'lang' => 'en',
            'bbox' => self::bounds(),
        ]);
        // Repeated "layer" parameters, which http_build_query cannot write.
        $url = self::searchUrl() . '?' . $query . '&layer=house&layer=street';

        $response = $this->call($url);
        if ($response === null) {
            return [];
        }

        $suggestions = [];
        foreach ((array)($response['features'] ?? []) as $feature) {
            $properties = is_array($feature) ? ($feature['properties'] ?? null) : null;
            if (!is_array($properties)) {
                continue;
            }

            $fields = PhotonAddress::fromFeature($properties, $term);
            // The service area rule, as for getAddress(): only suggest where
            // Clean Flow works. The field stays typeable for anything else.
            if ($fields === null || ($state !== '' && $fields['state'] !== $state)) {
                continue;
            }

            $label = PhotonAddress::label($fields);
            if (isset($suggestions[$label])) {
                continue;
            }

            $id = 'ph' . substr(sha1($label), 0, 32);
            $this->remember('photon_' . $id, $fields, self::RESOLVED_CACHE);
            $suggestions[$label] = ['id' => $id, 'label' => $label];

            if (count($suggestions) === self::MAX_SUGGESTIONS) {
                break;
            }
        }

        $suggestions = array_values($suggestions);
        $this->remember($cacheKey, $suggestions, self::SUGGESTION_CACHE);

        return $suggestions;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $id): ?array
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        $fields = $this->cached('photon_' . $id, self::RESOLVED_CACHE);

        /** @var array{street: string, suburb: string, state: string, postcode: string}|null $fields */
        return is_array($fields) ? $fields : null;
    }

    /**
     * One request, with every failure flattened to null.
     *
     * @param string $url Full request URL.
     * @return array<string, mixed>|null
     */
    private function call(string $url): ?array
    {
        try {
            $response = $this->http->get($url);
        } catch (Throwable $exception) {
            Log::warning('Address lookup (Photon) could not reach the provider: ' . $exception::class);

            return null;
        }

        if (!$response->isOk()) {
            Log::warning('Address lookup (Photon) returned HTTP ' . $response->getStatusCode());

            return null;
        }

        try {
            $decoded = $response->getJson();
        } catch (Throwable $exception) {
            Log::warning('Address lookup (Photon) returned a body I could not read: ' . $exception::class);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $key Cache key.
     * @param string $config Cache config to read from.
     * @return mixed
     */
    private function cached(string $key, string $config): mixed
    {
        try {
            return Cache::read($key, $this->cacheConfig($config));
        } catch (Throwable $exception) {
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
            Log::warning('Address lookup (Photon) could not write its cache: ' . $exception::class);
        }
    }

    /**
     * @param string $config Preferred cache config.
     * @return string
     */
    private function cacheConfig(string $config): string
    {
        return Cache::getConfig($config) === null ? 'default' : $config;
    }
}
