<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Address\AddressLookupInterface;
use App\Service\Address\GetAddressClient;
use App\Service\Address\PhotonClient;
use Cake\Core\Configure;
use Cake\Http\Response;
use Throwable;

/**
 * The address lookup the job request form talks to.
 *
 * Two actions, both GET, both JSON. suggest() is called as the customer types
 * and costs nothing. resolve() runs once, when they pick a suggestion, and is
 * the call that spends one of the plan's daily look-ups.
 *
 * This is a proxy rather than a direct browser call on purpose. It keeps the
 * API key on the server, lets the responses be cached once for everybody
 * instead of once per visitor, and puts our own throttle in front of the
 * provider's.
 *
 * Unlike the assistant, this is not open to the public. The job request form
 * is behind a customer login, so there is no visitor who needs this and no
 * reason to let an anonymous caller spend the allowance.
 */
class AddressesController extends AppController
{
    /**
     * @var string
     */
    private const SUGGEST_SCOPE = 'address_suggest';

    /**
     * @var string
     */
    private const RESOLVE_SCOPE = 'address_resolve';

    /**
     * @var \App\Service\Address\AddressLookupInterface|null
     */
    private ?AddressLookupInterface $lookup = null;

    /**
     * Lets the tests supply a stub, so the suite never needs a network, a key
     * or a quota.
     *
     * @param \App\Service\Address\AddressLookupInterface $lookup The lookup.
     * @return void
     */
    public function setLookup(AddressLookupInterface $lookup): void
    {
        $this->lookup = $lookup;
    }

    /**
     * Suggestions for a partial address.
     *
     * @return \Cake\Http\Response
     */
    public function suggest(): Response
    {
        $this->request->allowMethod(['get']);

        if (!$this->available()) {
            return $this->json(['suggestions' => []]);
        }

        if ($this->throttled(self::SUGGEST_SCOPE, 'suggestLimit') !== null) {
            // No Retry-After and no 429 here, deliberately. The browser treats
            // an empty list and a refusal the same way, and a customer who
            // types quickly should see the suggestions go quiet for a moment
            // rather than meet an error.
            return $this->json(['suggestions' => []]);
        }

        $term = $this->request->getQuery('q');
        $term = is_string($term) ? $term : '';

        return $this->json([
            'suggestions' => $this->lookup()->suggest($term),
        ]);
    }

    /**
     * The four fields for one chosen suggestion.
     *
     * @return \Cake\Http\Response
     */
    public function resolve(): Response
    {
        $this->request->allowMethod(['get']);

        if (!$this->available()) {
            return $this->json(['address' => null]);
        }

        if ($this->throttled(self::RESOLVE_SCOPE, 'resolveLimit') !== null) {
            return $this->json(['address' => null]);
        }

        $id = $this->request->getQuery('id');
        $id = is_string($id) ? $id : '';

        return $this->json([
            'address' => $this->lookup()->resolve($id),
        ]);
    }

    /**
     * The kill switch, and the case with no provider at all.
     *
     * With a getAddress() key, that is the provider. Without one, the free
     * Photon fallback answers instead, unless it has been turned off. Either
     * way a lookup that is unavailable answers as "no suggestions" rather than
     * as an error, so the form still works and the address is typed by hand.
     *
     * @return bool
     */
    private function available(): bool
    {
        if (!(bool)Configure::read('Address.enabled', true)) {
            return false;
        }

        return $this->hasApiKey() || $this->usesPhoton();
    }

    /**
     * @return bool
     */
    private function hasApiKey(): bool
    {
        return trim((string)Configure::read('Address.apiKey', '')) !== '';
    }

    /**
     * @return bool
     */
    private function usesPhoton(): bool
    {
        return PhotonClient::isActive();
    }

    /**
     * Per customer and IP throttle, on the counter table the public forms
     * already use.
     *
     * Keyed on the customer as well as the address, because this sits behind a
     * login and the account is the better identifier: a share house behind one
     * connection should not throttle each other.
     *
     * @param string $scope Counter scope.
     * @param string $configKey Which limit in the Address config to apply.
     * @return int|null Seconds to wait, or null when the request is allowed.
     */
    private function throttled(string $scope, string $configKey): ?int
    {
        $ip = (string)($this->request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = 'unknown';
        }

        $identity = $this->request->getAttribute('identity');
        $customerId = (string)($identity?->get('cust_id') ?? 'none');

        $salt = (string)Configure::read('FormRateLimit.keySalt', '');
        if ($salt === '') {
            $salt = (string)Configure::read('Security.salt', '');
        }

        try {
            $result = $this->fetchTable('FormRateLimits')->consume(
                hash_hmac('sha256', implode("\0", [$scope, $customerId, $ip]), $salt),
                $scope,
                (int)Configure::read("Address.$configKey.limit", 60),
                (int)Configure::read("Address.$configKey.window", 300),
            );
        } catch (Throwable $exception) {
            // Same trade as the assistant: a counter that cannot be read must
            // not become a closed door. The provider has its own rate limit
            // behind this one, so failing open here cannot run away with the
            // allowance.
            $this->log('Address lookup rate limit check failed: ' . $exception::class, 'warning');

            return null;
        }

        return $result['allowed'] ? null : max(1, (int)$result['retryAfter']);
    }

    /**
     * @return \App\Service\Address\AddressLookupInterface
     */
    private function lookup(): AddressLookupInterface
    {
        return $this->lookup ??= $this->usesPhoton() ? new PhotonClient() : new GetAddressClient();
    }

    /**
     * @param array<string, mixed> $payload Response body.
     * @return \Cake\Http\Response
     */
    private function json(array $payload): Response
    {
        // JSON_HEX_TAG for the same reason as the assistant endpoint: the
        // browser writes these with textContent so they are already inert
        // there, and this is the second layer for the day somebody renders the
        // body some other way. Address labels are G-NAF text and will not
        // contain a tag, but "will not" is not a control.
        return $this->response
            ->withType('application/json')
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
    }
}
