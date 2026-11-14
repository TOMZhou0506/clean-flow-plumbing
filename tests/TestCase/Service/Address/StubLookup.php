<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Address;

use App\Service\Address\AddressLookupInterface;

/**
 * Stands in for getAddress() so the suite never needs a network, a key, or any
 * of the provider's daily allowance.
 *
 * The same shape as RecordingClient on the assistant side: the values are
 * public so a test can change what the provider "says" between requests, and
 * the calls are counted so a test can prove the controller refused before
 * reaching the provider rather than merely producing an empty answer.
 */
class StubLookup implements AddressLookupInterface
{
    /**
     * @var list<array{id: string, label: string}>
     */
    public array $suggestions = [];

    /**
     * @var array{street: string, suburb: string, state: string, postcode: string}|null
     */
    public ?array $resolved = null;

    /**
     * @var int
     */
    public int $suggestCalls = 0;

    /**
     * @var int
     */
    public int $resolveCalls = 0;

    /**
     * @inheritDoc
     */
    public function suggest(string $term): array
    {
        $this->suggestCalls++;

        return $this->suggestions;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $id): ?array
    {
        $this->resolveCalls++;

        return $this->resolved;
    }
}
