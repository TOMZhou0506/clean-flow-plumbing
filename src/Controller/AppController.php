<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use App\Model\Entity\Admin;
use App\Service\Content\ContentCatalogue;
use App\Service\LoginRateLimiter;
use Authentication\Authenticator\UnauthenticatedException;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Log\Log;
use Throwable;

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/5/en/controllers.html#the-app-controller
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('FormProtection');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');

        /*
         * The AuthenticationMiddleware in Application.php provides the auth
         * service; this component is what exposes $this->Authentication to
         * controllers. Both are needed. I found that out when I had only the
         * middleware: every call to $this->Authentication was a fatal error
         * and all 23 tests came back 500.
         *
         * Loading it here makes authentication the default for every
         * controller. Anything that must stay public opts out explicitly with
         * addUnauthenticatedActions() in its beforeFilter(). I wanted it this
         * way round so that forgetting to protect a new action fails closed
         * rather than silently exposing it.
         */
        $this->loadComponent('Authentication.Authentication');

        /*
         * Enable the following component for recommended CakePHP form protection settings.
         * see https://book.cakephp.org/5/en/controllers/components/form-protection.html
         */
        //$this->loadComponent('FormProtection');
    }

    /**
     * Keeps signed-in pages out of the browser's cache.
     *
     * Server side checks cannot help if the browser never asks the server.
     * By default it is free to keep a rendered page in history and repaint it
     * from disk when somebody presses back, so a dashboard stays visible after
     * the account behind it has been deleted or logged out. Nothing has been
     * bypassed, the pixels are simply stale, but the person looking at the
     * screen cannot tell the difference and neither can the marker.
     *
     * withDisabledCache() sends no-store together with an expiry in the past,
     * which also defeats the back-forward cache, so the back button re-requests
     * the page and gets the redirect it should have got.
     *
     * Only applied where somebody is signed in. The landing page and the
     * contact form are public and benefit from being cached normally.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        if ($this->request->getAttribute('identity') !== null) {
            $this->setResponse($this->getResponse()->withDisabledCache());
        }
    }

    /**
     * Stops the request unless the signed-in account has the admin role.
     *
     * Authentication answers "who is this", which the middleware already
     * handles. This answers "are they allowed", which it does not. Being
     * logged in is not the same as being permitted, and treating the two as
     * one thing is how a staff account ends up reading customer enquiries.
     *
     * I throw rather than redirect. A redirect to the login page tells a
     * signed-in staff member to log in again, which is confusing and, worse,
     * makes "denied" and "not logged in" look identical from the outside. 403
     * says plainly that the account is known and refused.
     *
     * Call this from beforeFilter() in any controller holding customer or
     * compliance data.
     *
     * @return void
     * @throws \Authentication\Authenticator\UnauthenticatedException When nobody is signed in.
     * @throws \Cake\Http\Exception\ForbiddenException When the account is not an admin.
     */
    protected function requireAdmin(): void
    {
        $this->requireOfficeRoles([Admin::ROLE_ADMIN]);
    }

    /**
     * For pages the office and the manager both use, such as the plumber list.
     *
     * Staff accounts and customers are still turned away. The stored role is
     * checked on every request, like the other helpers, so a demotion or a
     * deactivation takes effect straight away.
     */
    protected function requireAdminOrManager(): void
    {
        $this->requireOfficeRoles([Admin::ROLE_ADMIN, Admin::ROLE_MANAGER]);
    }

    /**
     * Account administration belongs to the single manager, not an admin.
     */
    protected function requireManager(): void
    {
        $this->requireOfficeRoles([Admin::ROLE_MANAGER]);
    }

    /**
     * Both active office roles may change their own password.
     */
    protected function requireOfficeAccount(): void
    {
        $this->requireOfficeRoles([Admin::ROLE_ADMIN, Admin::ROLE_MANAGER, Admin::ROLE_STAFF]);
    }

    /**
     * The plumber workspace belongs only to active staff accounts.
     */
    protected function requirePlumber(): void
    {
        $this->requireOfficeRoles([Admin::ROLE_STAFF]);
    }

    /**
     * The website content editor, for the roles the client nominated in
     * Cms.editorRoles. Everyone else gets the same 403 as any office page.
     */
    protected function requireContentEditor(): void
    {
        $this->requireOfficeRoles(ContentCatalogue::editorRoles());
    }

    /**
     * Check the current database row, not a possibly stale role in the session.
     *
     * @param list<string> $allowedRoles Roles permitted for this action.
     */
    private function requireOfficeRoles(array $allowedRoles): void
    {
        $identity = $this->request->getAttribute('identity');

        // Not signed in at all.
        //
        // I throw UnauthenticatedException specifically, not Forbidden. The
        // AuthenticationMiddleware catches this one and applies the behaviour
        // configured per path: an HTML request is redirected to the login
        // form, while /api gets a bare 401 it can act on. Throwing Forbidden
        // here would give a logged-out visitor a 403 page instead of the
        // login screen.
        //
        // This branch matters because of event ordering. The controller's own
        // beforeFilter runs before the Authentication component's, so a
        // logged-out request reaches this method before the component gets a
        // chance to reject it. Without this check I would be reading ->role
        // off null.
        if ($identity === null) {
            throw new UnauthenticatedException('You need to sign in to view this page.');
        }

        // Re-read the account from the database on every request.
        //
        // The session authenticator trusts whatever was written into the
        // session at login and never looks at the database again, which is
        // what makes it cheap. It also means deleting an admin does nothing to
        // anyone already signed in as them: their session still holds a
        // perfectly valid looking identity, so they keep working, and pressing
        // back returns them to the dashboard of an account that no longer
        // exists. The same hole applies to demoting somebody from admin to
        // staff, which is worse, because revoking access is exactly the moment
        // you need it to take effect.
        //
        // One extra query per admin page is a fair price for "deleted means
        // deleted". If it ever shows up in profiling, the answer is to cache
        // the lookup for a few seconds, not to drop it.
        $adminId = $this->currentAdminId();

        if ($adminId === null) {
            // Signed in, but not as an office account at all. A customer who
            // follows an admin link lands here. That is a permissions answer,
            // not a deleted account, so their session is left alone.
            throw new ForbiddenException('Your account does not have access to this page.');
        }

        $admin = $this->fetchTable('Admins')
            ->find()
            ->where(['admin_id' => $adminId])
            ->first();

        if ($admin === null) {
            // The account is gone. End the session rather than leaving it to
            // expire on its own, otherwise the next request walks back in here
            // and repeats the same lookup for the next thirty minutes.
            $this->Authentication->logout();
            $this->request->getSession()->destroy();

            throw new UnauthenticatedException('That account no longer exists.');
        }

        if (!$admin->active) {
            $this->Authentication->logout();
            $this->request->getSession()->destroy();

            throw new UnauthenticatedException('That account has been deactivated.');
        }

        // A password change ends every session that signed in with the old one.
        //
        // A real sign-in stores the account row, hash included, in the session. If the stored
        // hash is no longer the one in the database, the password was changed (reset by email,
        // or changed on the account page from another device), so this session must not carry
        // on. Sessions without a hash, such as those the tests build by hand, are left alone.
        $sessionHash = $identity->get('admin_password');
        $passwordChanged = is_string($sessionHash)
            && $sessionHash !== ''
            && !hash_equals((string)$admin->admin_password, $sessionHash);
        if ($passwordChanged) {
            $this->Authentication->logout();
            $this->request->getSession()->destroy();

            throw new UnauthenticatedException('Your password was changed. Please sign in again.');
        }

        // The stored role, not the one in the session. A role changed after
        // sign-in has to bite immediately for the same reason as above.
        if (!in_array($admin->role, $allowedRoles, true)) {
            throw new ForbiddenException('Your account does not have access to this page.');
        }

        // Templates should use the current database role as well. A manager
        // promoted while signed in may still have "admin" in their session.
        $this->set('officeRole', $admin->role);
        $this->set('officeAccountId', $adminId);
    }

    /**
     * The signed-in admin's id, for read_by and audit_logs.user_id.
     *
     * Read admin_id off the identity, deliberately, rather than calling
     * $identity->getIdentifier(). That method looks like the obvious one and
     * is wrong for us: Authentication\Identity::getIdentifier() is hardcoded
     * to return get('id'), and our admins table has no id column, it has
     * admin_id. So it returns null for every real login.
     *
     * That is the exact failure the old comments here warned about and did not
     * catch: nothing throws, the audit trail just quietly records a null user
     * while every page still looks fine. It stayed hidden because the tests
     * signed in by writing a fabricated session that included an 'id' key the
     * real login never produces. I have taken that key out of the fixtures, so
     * these tests now fail if anyone reaches for getIdentifier() again.
     *
     * Customers are not admins and have their own cust_id, so this returns
     * null for them, which is correct: a customer never writes an audit row.
     *
     * @return int|null
     */
    protected function currentAdminId(): ?int
    {
        $identity = $this->request->getAttribute('identity');

        if ($identity === null) {
            return null;
        }

        $adminId = $identity->get('admin_id');

        return $adminId === null ? null : (int)$adminId;
    }

    /**
     * Drops a date range that runs backwards, and says so.
     *
     * A From later than its To can never match anything, so the office gets an
     * empty table and no clue why. Worse, it looks identical to "there are
     * genuinely no job requests this month", which is a conclusion somebody
     * could act on.
     *
     * Only the two date bounds are dropped, not the whole filter. Throwing away
     * a search term the person typed because they fumbled a date would be its
     * own small annoyance, and they can see from the message which part was
     * ignored.
     *
     * Equal dates are allowed on purpose. From and To on the same day is a
     * legitimate way of asking for one day's worth.
     *
     * The browser already blocks this through min and max on the two inputs,
     * so ordinarily this never fires. It is here because a query string can be
     * typed, edited, bookmarked or shared, and none of those go anywhere near
     * the form's validation.
     *
     * @param array<string, mixed> $filters The raw filters from the query string.
     */
    protected function withUsableDateRange(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if (!is_string($from) || !is_string($to)) {
            return $filters;
        }

        // Only compare two well-formed dates. A malformed one is already
        // discarded further down by the table's own normaliseDate(), and
        // comparing it here would report the wrong problem.
        $pattern = '~^\d{4}-\d{2}-\d{2}$~';
        if (!preg_match($pattern, $from) || !preg_match($pattern, $to)) {
            return $filters;
        }

        // Y-m-d sorts lexicographically in the same order it sorts
        // chronologically, so no parsing is needed to compare the two.
        if ($from <= $to) {
            return $filters;
        }

        // Kept under the 120 character line limit without splitting the string
        // across a concatenation, which would stop the i18n extractor seeing it
        // as one translatable message. "only" carries what the longer wording
        // spelled out: the rest of the filter was left alone.
        $this->Flash->error(__(
            'The From date must be on or before the To date, so only the date range was ignored.',
        ));

        $filters['from'] = null;
        $filters['to'] = null;

        return $filters;
    }

    /**
     * Clear only the account-and-IP counter after a successful login.
     *
     * The broad IP counter deliberately remains, so one successful credential
     * cannot be used to reset the source's per-minute allowance. A cleanup
     * failure must not turn a valid password into a failed login; it is logged
     * and the short-lived counter expires normally.
     *
     * @return void
     */
    protected function clearSuccessfulLoginRateLimit(): void
    {
        try {
            (new LoginRateLimiter())->clearSuccessfulAttempt($this->request);
        } catch (Throwable $exception) {
            Log::error('Could not clear a successful login rate-limit counter: {message}', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
