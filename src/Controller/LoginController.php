<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Admin;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * The single sign-in page.
 *
 * The site carried two login buttons, one for the office and one for
 * customers, and the client found that confusing. There is now one page for all
 * four roles (customer, plumber, admin, manager) and no role choice on it. The
 * account decides where the person lands.
 *
 * Which table is checked is worked out from the email that was typed, in
 * Application::getAuthenticationService(): an address that belongs to an office
 * account is checked against the office table only, any other address against
 * the customer table only. The two tables are never both tried for one request,
 * and a sign-in failure always gives the same message.
 *
 * Admins::login() and Customers::login() are gone now that this page covers
 * both kinds of account. /admins/login and /customers/login redirect here (see
 * config/routes.php) so an old bookmark or cached link still works.
 */
class LoginController extends AppController
{
    /**
     * Someone signed in with an office account.
     *
     * @var string
     */
    public const ROLE_ADMIN = 'admin';

    /**
     * Someone signed in with a customer account.
     *
     * @var string
     */
    public const ROLE_CUSTOMER = 'customer';

    /**
     * Anyone can reach the login page. That is the whole point of it.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->Authentication->allowUnauthenticated(['index']);
    }

    /**
     * Shows the form, and handles the sign-in.
     *
     * @return \Cake\Http\Response|null
     */
    public function index(): ?Response
    {
        $result = $this->Authentication->getResult();

        if ($result !== null && $result->isValid()) {
            $role = $this->signedInRole();
            // A new sign-in replaces the other kind of account in this browser.
            // Otherwise a customer page would use CustomerAuth while an office
            // URL could still recognise a manager/admin left in AdminAuth.
            $this->request->getSession()->delete(
                $role === self::ROLE_CUSTOMER ? 'AdminAuth' : 'CustomerAuth',
            );
            $this->clearSuccessfulLoginRateLimit();

            return $this->redirect($this->destinationFor($role));
        }

        if ($this->request->is('post')) {
            $this->takeAsLongAsARealAccountWould();

            // One message for every failure, on purpose. It names both fields together and
            // never says which one was wrong, so it cannot be used to find out which addresses
            // have an account: an unknown email, a wrong password and a deactivated account
            // all get exactly this.
            $this->Flash->error(__('Either email or password is incorrect.'));
        }

        return null;
    }

    /**
     * Make a failed sign in cost the same whether or not the account exists.
     *
     * Checking a real account's password means hashing the typed one, which is deliberately slow,
     * while an address with no active account is turned away straight away. Measured on this site
     * that was about 0.45 seconds against 0.25, which is enough to tell from outside which email
     * addresses have an account, including deactivated ones. So when there is no active account
     * for the address, the same amount of hashing is done here and thrown away.
     *
     * @return void
     */
    private function takeAsLongAsARealAccountWould(): void
    {
        $email = $this->request->getData('email');
        $password = $this->request->getData('password');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        $password = is_string($password) ? $password : '';

        $hasLiveAccount = $email !== '' && (
            $this->fetchTable('Admins')->find()->where(['admin_email' => $email, 'active' => true])->count() > 0
            || $this->fetchTable('Customers')->find()->where(['cust_email' => $email])->count() > 0
        );

        if (!$hasLiveAccount) {
            password_hash($password, PASSWORD_DEFAULT);
        }
    }

    /**
     * Where to send someone once they are in.
     *
     * getLoginRedirect() still wins, so anybody bounced off a page they asked
     * for lands back on it. These are only the defaults for someone who opened
     * the login page directly.
     *
     * @param string|null $role The role they signed in under.
     * @return array<string, mixed>|string
     */
    private function destinationFor(?string $role): array|string
    {
        $intended = $this->Authentication->getLoginRedirect();

        // Anything but a logout URL.
        //
        // getLoginRedirect() reads the ?redirect= query parameter, which is
        // whatever the last failed request happened to be. If that request was
        // a logout, honouring it means the user signs in and is logged out by
        // the very next redirect, over and over, with no way to tell what is
        // happening. The parameter is also visitor controlled, so this is not
        // only reachable by accident.
        //
        // A substring match is enough here: the only URLs in the app with
        // "logout" in them are logout actions.
        if ($intended !== null && !str_contains(strtolower($intended), 'logout')) {
            $path = parse_url($intended, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
                $customerPath = str_starts_with($path, '/customers/')
                    || in_array($path, ['/job-requests', '/job-requests/add'], true)
                    || (bool)preg_match('~^/job-requests/(confirmation|rate)/\d+$~', $path);
                if (($role === self::ROLE_CUSTOMER) === $customerPath) {
                    return $intended;
                }
            }
        }

        if ($role === self::ROLE_CUSTOMER) {
            return [
                'prefix' => false,
                'plugin' => null,
                'controller' => 'JobRequests',
                'action' => 'add',
            ];
        }

        // Where each office role lands: the manager on the job overview, a plumber on their own
        // dashboard, and an admin on the office job requests list. The manager's account pages
        // are one link away in the sidebar.
        $adminId = $this->currentAdminId();
        if ($adminId !== null) {
            $office = $this->fetchTable('Admins')->find()
                ->select(['role'])
                ->where(['admin_id' => $adminId])
                ->first();
            if ($office?->role === Admin::ROLE_MANAGER) {
                return [
                    'prefix' => false,
                    'plugin' => null,
                    'controller' => 'ManagerJobs',
                    'action' => 'index',
                ];
            }
            if ($office?->role === Admin::ROLE_STAFF) {
                return [
                    'prefix' => false,
                    'plugin' => null,
                    'controller' => 'Plumbers',
                    'action' => 'dashboard',
                ];
            }
        }

        return [
            'prefix' => false,
            'plugin' => null,
            'controller' => 'JobRequests',
            'action' => 'manage',
        ];
    }

    /**
     * The kind of account the person who is signed in has.
     *
     * Read from the identity rather than the session, because on the request
     * that signs someone in the session is only written after the controller
     * has returned. A customer identity carries cust_id and an office identity
     * carries admin_id, so there is no guessing involved.
     *
     * @return string|null
     */
    private function signedInRole(): ?string
    {
        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return null;
        }

        return $identity->offsetExists('cust_id') ? self::ROLE_CUSTOMER : self::ROLE_ADMIN;
    }
}
