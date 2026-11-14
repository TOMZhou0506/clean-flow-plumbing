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
 * @since     3.3.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 **/

namespace App;

use App\Middleware\FormRateLimitMiddleware;
use App\Middleware\HostHeaderMiddleware;
use App\Middleware\LoginRateLimitMiddleware;
use App\Middleware\SessionMiddleware;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManagerInterface;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Http\Session;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application setup class.
 *
 * This defines the bootstrapping logic and middleware layers you
 * want to use in your application.
 *
 * @extends \Cake\Http\BaseApplication
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * Load all the application configuration and bootstrap logic.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        // Call parent to load bootstrap from files.
        parent::bootstrap();
        $this->addPlugin('Authentication');

        // By default, does not allow fallback classes.
        FactoryLocator::add(
            'Table',
            (new TableLocator())->allowFallbackClass(false),
        );
    }

    /**
     * Setup the middleware queue your application will use.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        // I configure this separately because it takes options. I keep
        // httponly false so a JavaScript client can read the token. It is a
        // different cookie from the session and carries no authority alone.
        $csrf = new CsrfProtectionMiddleware([
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        $middlewareQueue
            // Catch any exceptions in the lower layers,
            // and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Validate Host header to prevent Host Header Injection attacks.
            ->add(new HostHeaderMiddleware())

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))

            // Add routing middleware.
            ->add(new RoutingMiddleware($this))

            // Parse various types of encoded request bodies so that they are
            // available as array through $request->getData()
            ->add(new BodyParserMiddleware())

            // Cross Site Request Forgery (CSRF) Protection Middleware
            ->add($csrf)

            // I put authentication AFTER CSRF so credentials are never
            // verified on a request whose CSRF token has not been checked.
            //
            // Worth knowing: when I merged main, git resolved this without a
            // conflict marker and left AuthenticationMiddleware registered
            // twice, once each side of the CSRF middleware, which ran the
            // whole authentication service twice per request. Keep it to one
            // registration.
            //
            // This ordering is safe with the login form because
            // templates/Login/index.php uses FormHelper, which emits the CSRF
            // token. A raw HTML form there would start failing.
            ->add(new SessionMiddleware())
            // Reserve login capacity before Authentication performs the
            // comparatively expensive password hash. The middleware ignores
            // all requests except POSTs to the three login actions.
            ->add(new LoginRateLimitMiddleware())
            ->add(new AuthenticationMiddleware($this))
            // Job-request throttling needs the authenticated customer ID, so
            // this middleware runs after Authentication. Contact requests do
            // not require an identity and are grouped by connecting IP only.
            ->add(new FormRateLimitMiddleware());

        return $middlewareQueue;
    }

    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     * @return void
     * @link https://book.cakephp.org/5/en/development/dependency-injection.html#dependency-injection
     */
    public function services(ContainerInterface $container): void
    {
        // Allow your Tables to be dependency injected
        //$container->delegate(new \Cake\ORM\Locator\TableContainer());
    }

    /**
     * Register custom event listeners here
     *
     * @param \Cake\Event\EventManagerInterface $eventManager
     * @return \Cake\Event\EventManagerInterface
     * @link https://book.cakephp.org/5/en/core-libraries/events.html#registering-listeners
     */
    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        // $eventManager->on(new SomeCustomListenerClass());

        return $eventManager;
    }

    /**
     * Returns a service provider instance.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        // Older sessions can contain both identities because the two account
        // types use different session keys. Never choose an identity based on
        // which URL is requested when that happens: make the visitor sign in
        // again instead. This runs before either session authenticator reads it.
        $session = $request->getAttribute('session');
        if ($session instanceof Session && $session->check('AdminAuth') && $session->check('CustomerAuth')) {
            $session->delete('AdminAuth');
            $session->delete('CustomerAuth');
        }

        // I redirect a browser hitting a page to the login form, but I want
        // /api to answer 401 instead. An API client needs a status code it can
        // act on, and a 302 to an HTML login page is useless to fetch().
        // Setting unauthenticatedRedirect makes the plugin redirect
        // unconditionally, so I only apply it off /api.
        $config = ['queryParam' => 'redirect'];

        $path = $request->getUri()->getPath();

        // /job-requests is shared between the customer pages and the office
        // list, so the prefix alone cannot say which authenticators to load.
        //
        // This used to read "everything under /job-requests is the customer
        // area, except /job-requests/manage". That is an exception list, and
        // an exception list fails in the wrong direction: a new office URL
        // under this prefix gets the customer authenticators by default, the
        // admin's session is never examined, and they are bounced to a login
        // page instead of the action running. It is a silent failure, which is
        // why the status buttons appeared to do nothing at all.
        //
        // Inverted into an allowlist of the URLs that genuinely belong to a
        // signed-in customer. Anything else under the prefix is treated as
        // office, so a new admin URL is safe by default and the worst case is
        // an honest 403 rather than a page that quietly does nothing.
        //
        // Matched against the routes rather than free text: /job-requests and
        // /job-requests/add exactly, and /job-requests/confirmation/{id} and
        // /job-requests/rate/{id}, which is where a customer rates a finished job.
        $normalised = rtrim($path, '/');
        // The address lookup endpoints are here too. They are only reachable
        // from the customer's own job request form, and leaving them off this
        // list would hand them the office authenticators: the customer's
        // session would be ignored and the browser would get a redirect to the
        // login page where it expected JSON.
        $customerJobRequests = in_array($normalised, [
            '/job-requests',
            '/job-requests/add',
            '/job-requests/address/suggest',
            '/job-requests/address/resolve',
        ], true)
            || (bool)preg_match('~^/job-requests/(confirmation|rate)/\d+$~', $normalised);

        $customerArea = str_starts_with($path, '/customers') || $customerJobRequests;

        // The unified login page.
        //
        // One page at /login serves all four roles and there is no role
        // choice on it. On this page the decision cannot come from the URL,
        // because everyone shares it, so it comes from the email that was
        // typed: an address that belongs to an office account is checked
        // against the office table only, and any other address against the
        // customer table only.
        //
        // The two tables are never both tried for one request. Each request
        // loads exactly one pair of authenticators, so an office identity is
        // never written to the customer session key, or the other way round.
        // Sending an unknown address to the customer side is safe because the
        // password identifier still hashes the password when no account is
        // found, so every outcome costs about the same.
        $unifiedLogin = rtrim($path, '/') === '/login';
        $loginPost = $unifiedLogin && strtoupper($request->getMethod()) === 'POST';

        if ($loginPost) {
            $body = $request->getParsedBody();
            $email = is_array($body) && is_string($body['email'] ?? null) ? $body['email'] : '';
            $email = mb_strtolower(trim($email));

            // Any office account counts here, active or not. A deactivated
            // office account must be refused by the office table, not fall
            // through to a customer account that happens to share the address.
            $isOfficeAddress = $email !== '' && FactoryLocator::get('Table')
                ->get('Admins')
                ->find()
                ->where(['admin_email' => $email])
                ->count() > 0;
            $customerArea = $email !== '' && !$isOfficeAddress;
        }

        if (!str_starts_with($path, '/api')) {
            // Everything now funnels to the one login page. The old
            // /admins/login and /customers/login pages are gone; routes.php
            // redirects those URLs here instead.
            $config['unauthenticatedRedirect'] = [
                'prefix' => false,
                'plugin' => false,
                'controller' => 'Login',
                'action' => 'index',
            ];
        }

        $service = new AuthenticationService($config);

        // On a GET to /login nobody has typed an address yet, so load only the
        // two session authenticators. Someone already signed in as either kind
        // is recognised and sent to their own dashboard rather than being
        // shown a login form they do not need. The session keys differ, so the
        // two cannot be confused for one another.
        if ($unifiedLogin && !$loginPost) {
            $service->loadAuthenticator('adminSession', [
                'className' => 'Authentication.Session',
                'identifier' => [
                    'className' => 'Authentication.Password',
                    'fields' => [
                        'username' => 'admin_email',
                        'password' => 'admin_password',
                    ],
                    'resolver' => [
                        'className' => 'Authentication.Orm',
                        'userModel' => 'Admins',
                        'finder' => 'active',
                    ],
                ],
                'sessionKey' => 'AdminAuth',
            ]);
            $service->loadAuthenticator('customerSession', [
                'className' => 'Authentication.Session',
                'identifier' => [
                    'className' => 'Authentication.Password',
                    'fields' => [
                        'username' => 'cust_email',
                        'password' => 'cust_password_hash',
                    ],
                    'resolver' => [
                        'className' => 'Authentication.Orm',
                        'userModel' => 'Customers',
                    ],
                ],
                'sessionKey' => 'CustomerAuth',
            ]);

            return $service;
        }

        // Customer and admin accounts live in different tables and use
        // different session keys. Only load the authenticators for the area
        // being requested; otherwise an existing admin session can be
        // mistaken for a customer session (and vice versa).
        if ($customerArea) {
            $customerIdentifier = [
                'className' => 'Authentication.Password',
                'fields' => [
                    'username' => 'cust_email',
                    'password' => 'cust_password_hash',
                ],
                'resolver' => [
                    'className' => 'Authentication.Orm',
                    'userModel' => 'Customers',
                ],
            ];
            $service->loadAuthenticator('customerSession', [
                'className' => 'Authentication.Session',
                'identifier' => $customerIdentifier,
                'sessionKey' => 'CustomerAuth',
            ]);
            // loginUrl has to be the URL this request actually arrived on.
            //
            // DefaultUrlChecker runs Router::url() over whatever it is given
            // and compares the single result against the current path, so an
            // array is read as one route rather than a list of alternatives.
            // There is no way to say "either of these two URLs"; the value has
            // to be resolved per request instead.
            $service->loadAuthenticator('customerForm', [
                'className' => 'Authentication.Form',
                // These are the names of the fields in the POSTed form, not
                // the database columns. The identifier below still maps to
                // cust_email and cust_password_hash. The unified login page
                // posts plain email and password field names.
                'fields' => ['username' => 'email', 'password' => 'password'],
                'loginUrl' => Router::url([
                    'prefix' => false,
                    'plugin' => null,
                    'controller' => 'Login',
                    'action' => 'index',
                ]),
                'identifier' => $customerIdentifier,
            ]);

            return $service;
        }

        $adminFields = [
            'username' => 'admin_email',
            'password' => 'admin_password',
        ];

        // In plugin v4 the identifier is configured PER AUTHENTICATOR under
        // an 'identifier' key. I wasted time on this: earlier versions had a
        // global $service->loadIdentifier(), which no longer exists.
        //
        // The array is FLAT with a 'className' key. I first nested it as
        // ['Authentication.Password' => [...]] and got "Identifier
        // configuration must specify a class name."
        $adminIdentifier = [
            'className' => 'Authentication.Password',
            'fields' => $adminFields,
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'Admins',
                'finder' => 'active',
            ],
        ];

        // I check the session first because it happens on every request and
        // is cheap.
        $service->loadAuthenticator('adminSession', [
            'className' => 'Authentication.Session',
            'identifier' => $adminIdentifier,
            'sessionKey' => 'AdminAuth',
        ]);

        // The form authenticator only applies to the login POST. I took
        // Router::url from main rather than a hardcoded path, because the
        // deployed site may sit in a subdirectory where that stops matching.
        $service->loadAuthenticator('adminForm', [
            'className' => 'Authentication.Form',
            // Request field names, not columns. See the note on customerForm.
            'fields' => ['username' => 'email', 'password' => 'password'],
            'loginUrl' => Router::url([
                'prefix' => false,
                'plugin' => null,
                'controller' => 'Login',
                'action' => 'index',
            ]),
            'identifier' => $adminIdentifier,
        ]);

        return $service;
    }
}
