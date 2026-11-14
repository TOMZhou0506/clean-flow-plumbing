<?php
declare(strict_types=1);

namespace App\Service\Address;

/**
 * What the job request form needs from an address provider.
 *
 * The controller talks to this rather than to getAddress() directly, for the
 * same reason AssistantClientInterface exists: the tests get a stub with no
 * network and no key, and if the team ever changes provider the replacement is
 * one new class rather than an edit spread through a controller.
 */
interface AddressLookupInterface
{
    /**
     * Suggestions for a partial address.
     *
     * Never throws. Anything that goes wrong gives back an empty list, because
     * the customer is mid-keystroke and a failure here must look like "no
     * matches" rather than a broken form.
     *
     * @param string $term What the customer has typed so far.
     */
    public function suggest(string $term): array;

    /**
     * Turn a chosen suggestion into the four fields the form stores.
     *
     * @param string $id The suggestion id.
     * @return array{street: string, suburb: string, state: string, postcode: string}|null
     */
    public function resolve(string $id): ?array;
}
