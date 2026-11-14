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
 * @since     3.0.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */

use App\Mailer\Transport\LogTransport;
use Cake\Chronos\Chronos;
use Cake\Core\Configure;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\ConnectionHelper;
use Migrations\TestSuite\Migrator;

/**
 * Test runner bootstrap.
 *
 * Add additional configuration/setup your application needs when running
 * unit tests in this file.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/config/bootstrap.php';

// Existing controller tests exercise authentication behaviour rather than
// throttling. The dedicated middleware tests enable it with small isolated
// limits, so no test suite sends a burst of password guesses.
Configure::write('LoginRateLimit.enabled', false);
// Form throttling has its own isolated middleware tests. Keeping it disabled
// elsewhere prevents unrelated controller tests sharing counters.
Configure::write('FormRateLimit.enabled', false);
// Office accounts must use the site's mail domain. The fixtures use example.com, so the
// tests run with that domain; the dedicated tests change it to check the rule itself.
Configure::write('Mail.officeDomain', 'example.com');

// No test may send real email, whatever this machine's config/app_local.php says
// (a developer or the server may have SMTP set there). Tests that care about
// the transport set their own and restore this one.
TransportFactory::drop('default');
TransportFactory::setConfig('default', ['className' => LogTransport::class]);

if (empty($_SERVER['HTTP_HOST']) && !Configure::read('App.fullBaseUrl')) {
    Configure::write('App.fullBaseUrl', 'http://localhost');
}

// Fixate now to avoid one-second-leap-issues
Chronos::setTestNow(Chronos::now());

// Fixate sessionid early on, as php7.2+
// does not allow the sessionid to be set after stdout
// has been written to.
session_id('cli');

// Connection aliasing needs to happen before migrations are run.
// Otherwise, table objects inside migrations would use the default datasource
ConnectionHelper::addTestAliases();

// Use migrations to build test database schema.
//
// Will rebuild the database if the migration state differs
// from the migration history in files.
//
// If you are not using CakePHP's migrations you can
// hook into your migration tool of choice here or
// load schema from a SQL dump file with
// use Cake\TestSuite\Fixture\SchemaLoader;
// (new SchemaLoader())->loadSqlFiles('./tests/schema.sql', 'test');

(new Migrator())->run();
