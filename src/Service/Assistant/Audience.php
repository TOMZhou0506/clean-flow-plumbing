<?php
declare(strict_types=1);

namespace App\Service\Assistant;

use Cake\Http\ServerRequest;

/**
 * Works out which knowledge entries the person asking is allowed to reach.
 *
 * This is the access control for the assistant, and it lives on the server for
 * the obvious reason: the browser must never get a say in which audience it
 * belongs to. The request body is not consulted at all. Everything here comes
 * from the session the authentication middleware already established.
 *
 * Audiences widen rather than replace. A signed-in customer keeps everything a
 * signed-out visitor can see and gains the customer entries on top, because the
 * public answers are still the right ones for most of what they will ask.
 *
 * Office roles deliberately do not inherit the customer audience. The customer
 * entries are written in the second person about "your requests", which would be
 * actively misleading to an admin looking at everybody's.
 */
class Audience
{
    /**
     * @var string
     */
    public const PUBLIC = 'public';

    /**
     * @var string
     */
    public const CUSTOMER = 'customer';

    /**
     * Whether the assistant is offered to this request at all.
     *
     * The team scoped this feature to the public and to customers. Office
     * accounts do not get it: an admin, a plumber or the manager has the
     * sidebar, the run sheet and each other, and an assistant that answers from
     * a customer-facing knowledge base is no help to them anyway.
     *
     * Fails closed. Signed out and customers are allowed; anything else, which
     * means every office role and any role added later, is not. A new role
     * therefore has to be let in deliberately rather than inheriting access
     * because somebody forgot to add it to a deny list.
     *
     * @param \Cake\Http\ServerRequest $request The current request.
     * @return bool
     */
    public static function isAvailableTo(ServerRequest $request): bool
    {
        $identity = $request->getAttribute('identity');

        if ($identity === null) {
            return true;
        }

        // Customers and office accounts live in different tables with different
        // primary keys, so which key is present identifies the kind of account
        // without having to trust a role string.
        return $identity->get('cust_id') !== null;
    }

    /**
     * The audiences this request may read.
     *
     * Only two, now that the assistant is a public and customer feature. The
     * office roles were removed rather than left in as unreachable branches,
     * because a branch nobody can reach is a branch nobody checks, and this one
     * decides who sees what.
     *
     * Audiences widen rather than replace. A signed-in customer keeps
     * everything a signed-out visitor sees and gains the customer entries on
     * top, because the public answers are still right for most of what they ask.
     *
     * @param \Cake\Http\ServerRequest $request The current request.
     * @return list<string>
     */
    public static function forRequest(ServerRequest $request): array
    {
        return self::isAvailableTo($request) && $request->getAttribute('identity') !== null
            ? [self::PUBLIC, self::CUSTOMER]
            : [self::PUBLIC];
    }

    /**
     * A short label for the unanswered-question log, so the team can tell who
     * was asking without storing anything identifying.
     *
     * @param list<string> $audiences Audiences from forRequest().
     * @return string
     */
    public static function label(array $audiences): string
    {
        return in_array(self::CUSTOMER, $audiences, true) ? self::CUSTOMER : self::PUBLIC;
    }
}
