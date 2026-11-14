<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        /*
         * Here, we are connecting '/' (base path) to a controller called 'Pages',
         * its action called 'display', and we pass a param to select the view file
         * to use (in this case, templates/Pages/home.php)...
         */
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);

        /*
         * ...and connect the rest of 'Pages' controller's URLs.
         */
        $builder->connect('/pages/*', 'Pages::display');

        /*
         * The single sign-in page.
         *
         * The site used to have two login buttons and the client could not
         * tell which one they wanted. One page now, with an explicit choice of
         * Office staff or Customer, and it sends you to the matching
         * dashboard.
         *
         * The separate Admins::login() and Customers::login() pages are gone.
         * /admins/login and /customers/login redirect here instead of 404ing,
         * so any old bookmark or cached link still lands somewhere useful.
         */
        $builder->connect(
            '/login',
            ['controller' => 'Login', 'action' => 'index'],
        )->setMethods(['GET', 'POST']);
        // Forgot password, for office accounts. Two steps: ask for a code, then use it.
        $builder->connect(
            '/forgot-password',
            ['controller' => 'PasswordResets', 'action' => 'request'],
        )->setMethods(['GET', 'POST']);
        $builder->connect(
            '/reset-password',
            ['controller' => 'PasswordResets', 'action' => 'reset'],
        )->setMethods(['GET', 'POST']);
        $builder->redirect('/admins/login', '/login');
        $builder->redirect('/customers/login', '/login');

        $builder->connect(
            '/customers/register',
            ['controller' => 'Customers', 'action' => 'register'],
        )->setMethods(['GET', 'POST']);
        $builder->connect(
            '/customers/logout',
            ['controller' => 'Customers', 'action' => 'logout'],
        )->setMethods(['POST']);
        $builder->connect(
            '/job-requests/add',
            ['controller' => 'JobRequests', 'action' => 'add'],
        )->setMethods(['GET', 'POST']);
        $builder->connect(
            '/job-requests',
            ['controller' => 'JobRequests', 'action' => 'index'],
        )->setMethods(['GET']);
        /*
         * The address lookup behind the job request form.
         *
         * Deliberately under /job-requests rather than at /addresses.
         * Application::getAuthenticationService() decides which authenticators
         * to load from the path, and only an allowlisted path under this
         * prefix counts as the customer area. A tidier /addresses/suggest
         * would be treated as office, the customer's session would never be
         * examined, and the lookup would answer a redirect to the login page
         * instead of JSON. Both paths are in that allowlist.
         *
         * No setMethods() here, for the reason set out under the status route
         * below: a verb constraint on the route means a POST does not match it
         * at all, falls through to fallbacks(), and is reinterpreted as
         * JobRequests::address('suggest'). That is a confusing 404 for what is
         * really a wrong verb. Both actions call allowMethod(['get']) instead,
         * so the request reaches the controller and gets an honest 405.
         */
        $builder->connect(
            '/job-requests/address/suggest',
            ['controller' => 'Addresses', 'action' => 'suggest'],
        );
        $builder->connect(
            '/job-requests/address/resolve',
            ['controller' => 'Addresses', 'action' => 'resolve'],
        );

        /*
         * The office list of every customer's job requests.
         *
         * Declared before the customer routes below so that /job-requests/manage
         * is not swallowed by the confirmation pattern. It is a different
         * audience looking at the same table: JobRequestsController::index() is
         * the customer's own requests, manage() is all of them.
         */
        $builder->connect(
            '/job-requests/manage',
            ['controller' => 'JobRequests', 'action' => 'manage'],
        );
        $builder->connect('/plumber-availability', [
            'controller' => 'PlumberAvailability', 'action' => 'index',
        ]);
        $builder->connect('/plumber-availability/dismiss/{id}', [
            'controller' => 'PlumberAvailability', 'action' => 'dismiss',
        ])->setPatterns(['id' => '\\d+'])->setPass(['id']);
        // The website content editor (the CMS). history and restore come
        // before {group} so they are not read as group names.
        $builder->connect('/website-content', ['controller' => 'SiteContents', 'action' => 'index']);
        $builder->connect('/website-content/history', ['controller' => 'SiteContents', 'action' => 'history']);
        $builder->connect('/website-content/restore', ['controller' => 'SiteContents', 'action' => 'restore']);
        $builder->connect('/website-content/{group}', [
            'controller' => 'SiteContents', 'action' => 'edit',
        ])->setPatterns(['group' => '[a-z]+'])->setPass(['group']);
        $builder->connect(
            '/job-requests/manage/export',
            ['controller' => 'JobRequests', 'action' => 'exportCsv'],
        );
        /*
         * No setMethods() here, and that omission is the fix for "the status
         * never changes".
         *
         * setMethods() constrains URL *generation* as well as matching. Route
         *::match() assumes GET when the url array carries no _method, so a
         * POST-only route refuses to generate a link at all. The postLink in
         * manage_view.php therefore fell through to fallbacks() and produced
         * /job-requests/changeStatus/8 instead of /job-requests/manage/status/8.
         *
         * That URL still reached the controller, so it looked harmless. It was
         * not: Application::getAuthenticationService() decides which
         * authenticators to load from the path prefix, and anything under
         * /job-requests that is not /job-requests/manage is treated as the
         * customer area. So the admin's session was never even looked at, the
         * request was bounced to the login page, and the status silently
         * stayed where it was.
         *
         * The verb is still enforced, just in the controller, where
         * changeStatus() calls allowMethod(). A GET now reaches the action and
         * gets an honest 405 rather than being quietly reinterpreted.
         */
        $builder->connect(
            '/job-requests/manage/status/{id}',
            ['controller' => 'JobRequests', 'action' => 'changeStatus'],
        )->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->connect(
            '/job-requests/manage/assign/{id}',
            ['controller' => 'JobRequests', 'action' => 'assign'],
        )->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->connect(
            '/job-requests/manage/unassign/{id}',
            ['controller' => 'JobRequests', 'action' => 'unassign'],
        )->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->connect(
            '/job-requests/manage/proceed/{id}',
            ['controller' => 'JobRequests', 'action' => 'proceed'],
        )->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->connect(
            '/job-requests/manage/availability',
            ['controller' => 'JobRequests', 'action' => 'availability'],
        );
        $builder->connect(
            '/job-requests/manage/{id}',
            ['controller' => 'JobRequests', 'action' => 'manageView'],
        )->setPatterns(['id' => '\d+'])->setPass(['id']);

        $builder->connect(
            '/job-requests/confirmation/{id}',
            ['controller' => 'JobRequests', 'action' => 'confirmation'],
            ['pass' => ['id'], 'id' => '\\d+'],
        )->setMethods(['GET']);

        $builder->connect(
            '/manager-jobs',
            ['controller' => 'ManagerJobs', 'action' => 'index'],
        )->setMethods(['GET']);
        $builder->connect(
            '/manager-jobs/view/{id}',
            ['controller' => 'ManagerJobs', 'action' => 'view'],
        )->setPatterns(['id' => '\\d+'])->setPass(['id'])->setMethods(['GET']);

        /*
         * The public contact form, on a short URL.
         *
         * The landing page had three calls to action pointing at the contact
         * form and two of them linked to /contact, which was not a route, had
         * no controller behind it, and did not resolve through fallbacks()
         * either. The "Get a Quote" button in the header, which is the most
         * prominent thing on the page, returned a 404.
         *
         * I connected the URL rather than editing the three links, because
         * /contact is the address you would put on a van or a business card
         * and it should keep working. /contacts/add still resolves through
         * fallbacks(), so both spellings are live and neither can break the
         * other later.
         */
        $builder->connect('/contact', ['controller' => 'Contacts', 'action' => 'add']);

        /*
         * The privacy collection notice, on a short URL.
         *
         * It resolves through /pages/privacy as well, because Pages::display()
         * takes the template name, but /privacy is what goes in a footer and
         * what somebody types. Same reasoning as /contact above.
         */
        $builder->connect('/privacy', ['controller' => 'Pages', 'action' => 'display', 'privacy']);

        /*
         * The enquiry dashboard needs no explicit routes. It is a normal
         * CakePHP controller, so fallbacks() below gives it
         * /contact-submissions, /contact-submissions/view/12 and
         * /contact-submissions/change-status/12, the same as Admins and
         * Properties.
         *
         * The /admin and /admin/* routes that used to be here served the
         * React single page app from webroot/admin. That app has been removed
         * in favour of server rendered templates.
         */

        /*
         * Admin dashboard JSON API.
         *
         * Declared BEFORE fallbacks(), which registers catch-all routes -
         * anything after it is unreachable and /api/* would fall through to
         * CakePHP's default controller conventions.
         */
        $builder->scope('/api', function (RouteBuilder $api): void {
            // JSON only. Without this Cake content-negotiates and can return
            // an HTML error page the React client cannot parse.
            $api->setExtensions(['json']);

            // ---- session ----
            $api->connect(
                '/session',
                ['controller' => 'Session', 'action' => 'show', 'prefix' => 'Api'],
            )->setMethods(['GET']);

            $api->connect(
                '/session/logout',
                ['controller' => 'Session', 'action' => 'logout', 'prefix' => 'Api'],
            )->setMethods(['POST']);

            // ---- submissions ----
            // Literal routes first. /submissions/stats must be matched before
            // the {id} pattern, or "stats" is parsed as an id and returns 400.
            $api->connect(
                '/submissions/stats',
                ['controller' => 'Submissions', 'action' => 'stats', 'prefix' => 'Api'],
            )->setMethods(['GET']);

            $api->connect(
                '/submissions/export',
                ['controller' => 'Submissions', 'action' => 'export', 'prefix' => 'Api'],
            )->setMethods(['GET']);

            $api->connect(
                '/submissions/bulk-status',
                ['controller' => 'Submissions', 'action' => 'bulkStatus', 'prefix' => 'Api'],
            )->setMethods(['POST']);

            $api->connect(
                '/submissions',
                ['controller' => 'Submissions', 'action' => 'index', 'prefix' => 'Api'],
            )->setMethods(['GET']);

            // Pattern routes last. The \d+ constraint means a non-numeric id
            // never reaches the controller.
            $api->connect(
                '/submissions/{id}',
                ['controller' => 'Submissions', 'action' => 'view', 'prefix' => 'Api'],
                ['pass' => ['id'], 'id' => '\d+'],
            )->setMethods(['GET']);

            $api->connect(
                '/submissions/{id}',
                ['controller' => 'Submissions', 'action' => 'updateStatus', 'prefix' => 'Api'],
                ['pass' => ['id'], 'id' => '\d+'],
            )->setMethods(['PATCH', 'POST']);
        });

        /*
         * Connect catchall routes for all controllers.
         *
         * The `fallbacks` method is a shortcut for
         *
         * ```
         * $builder->connect('/{controller}', ['action' => 'index']);
         * $builder->connect('/{controller}/{action}/*', []);
         * ```
         *
         * It is NOT recommended to use fallback routes after your initial prototyping phase!
         * See https://book.cakephp.org/5/en/development/routing.html#fallbacks-method for more information
         */
        $builder->fallbacks();
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};
