<?php

use function Cake\Core\env;

/*
 * Local configuration file to provide any overrides to your app.php configuration.
 * Copy and save this file as app_local.php and make changes as required.
 * Note: It is not recommended to commit files with credentials such as app_local.php
 * into source code version control.
 */
return [
    /*
     * Debug Level:
     *
     * Production Mode:
     * false: No error messages, errors, or warnings shown.
     *
     * Development Mode:
     * true: Errors and warnings shown.
     */
    'debug' => filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN),

    /*
     * Security and encryption configuration
     *
     * - salt - A random string used in security hashing methods.
     *   The salt value is also used as the encryption key.
     *   You should treat it as extremely sensitive data.
     */
    'Security' => [
        'salt' => env('SECURITY_SALT', '__SALT__'),
    ],

    'LoginRateLimit' => [
        'enabled' => filter_var(env('LOGIN_RATE_LIMIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'keySalt' => env('LOGIN_RATE_LIMIT_KEY_SALT', ''),
        'accountIp' => [
            'limit' => (int)env('LOGIN_RATE_LIMIT_ACCOUNT_LIMIT', 5),
            'window' => (int)env('LOGIN_RATE_LIMIT_ACCOUNT_WINDOW', 900),
        ],
        'ip' => [
            'limit' => (int)env('LOGIN_RATE_LIMIT_IP_LIMIT', 30),
            'window' => (int)env('LOGIN_RATE_LIMIT_IP_WINDOW', 60),
        ],
    ],

    'FormRateLimit' => [
        'enabled' => filter_var(env('FORM_RATE_LIMIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'keySalt' => env('FORM_RATE_LIMIT_KEY_SALT', ''),
        'contact' => [
            'limit' => (int)env('FORM_RATE_LIMIT_CONTACT_LIMIT', 5),
            'window' => (int)env('FORM_RATE_LIMIT_CONTACT_WINDOW', 900),
        ],
        'jobRequest' => [
            'limit' => (int)env('FORM_RATE_LIMIT_JOB_LIMIT', 5),
            'window' => (int)env('FORM_RATE_LIMIT_JOB_WINDOW', 900),
        ],
    ],

    /*
     * These are Cloudflare's public "always pass" test keys. They let a local
     * developer exercise the complete Turnstile flow without weakening the
     * production site. Replace them with the widget keys from Cloudflare in
     * the deployed environment; never commit a production secret key.
     */
    'Turnstile' => [
        'siteKey' => env('TURNSTILE_SITE_KEY', '1x00000000000000000000AA'),
        'secretKey' => env('TURNSTILE_SECRET_KEY', '1x0000000000000000000000000000000AA'),
    ],

    /*
     * Connection information used by the ORM to connect
     * to your application's datastores.
     *
     * See app.php for more configuration options.
     */
    'Datasources' => [
        'default' => [
            'host' => 'localhost',
            /*
             * CakePHP will use the default DB port based on the driver selected
             * MySQL on MAMP uses port 8889, MAMP users will want to uncomment
             * the following line and set the port accordingly
             */
            //'port' => 'non_standard_port_number',

            'username' => 'FIT3047',
            'password' => 'FIT3047',

            'database' => 'clean_flow_plumbing_db',
            /*
             * If not using the default 'public' schema with the PostgreSQL driver
             * set it here.
             */
            //'schema' => 'myapp',

            /*
             * You can use a DSN string to set the entire configuration
             */
            'url' => env('DATABASE_URL', null),
        ],

        /*
         * The test connection is used during the test suite.
         */
        'test' => [
            'host' => 'localhost',
            //'port' => 'non_standard_port_number',
            'username' => 'FIT3047',
            'password' => 'FIT3047',
            'database' => 'clean_flow_plumbing_db',
            //'schema' => 'myapp',
            'url' => env('DATABASE_TEST_URL', 'sqlite://127.0.0.1/tmp/tests.sqlite'),
        ],
    ],

    /*
     * Email configuration.
     *
     * By default emails are written to logs/email.log and not sent, which is
     * what you want on a development machine. Leave this block alone locally.
     *
     * On the cPanel server, replace it in that server's own config/app_local.php
     * (which is not in Git) with the settings below, and fill in the mailbox
     * password there. Never put the password in this example file, in app.php,
     * or in a commit.
     *
     * Connect to the mail service on the server itself. The shared server does
     * not let outside machines reach its mail ports (465, 587 and 993 time out
     * from a laptop), so a connection from the site to the same machine is the
     * reliable choice.
     *
     *     'EmailTransport' => [
     *         'default' => [
     *             'className' => \Cake\Mailer\Transport\SmtpTransport::class,
     *             'host' => 'localhost',
     *             'port' => 25,
     *             'username' => 'manager@u26s2248.iedev.org',
     *             'password' => 'THE MAILBOX PASSWORD',
     *             'tls' => false,
     *             'timeout' => 30,
     *         ],
     *     ],
     *
     * Then check it works with: bin/cake send_test_email your.address@gmail.com
     *
     * If port 25 does not work, try these on the server, one at a time, and
     * run the test command after each:
     *   - port 587 with host 'localhost' and 'tls' => true
     *   - host 'ssl://localhost' with port 465 and 'tls' => false (the server's
     *     certificate may not match "localhost"; if the error mentions a
     *     certificate, use the next option)
     *   - host 'ssl://u26s2248.iedev.org' with port 465 and 'tls' => false
     */

    /*
     * Website assistant.
     *
     * Get a key from https://aistudio.google.com/apikey and paste it below in
     * your own config/app_local.php, which is gitignored. Do not put it in
     * app.php, do not commit it, and do not paste it into chat or a PR.
     *
     * Leave it empty and the assistant still runs: it answers "I do not have
     * an answer for that one" every time and never calls out to anything,
     * which is also the right setup for anyone who does not want to spend
     * their own quota while working on something else.
     *
     * The model and endpoint live in app.php and only need overriding here to
     * try a different Gemini release.
     */
    'Assistant' => [
        'apiKey' => '',
    ],

    /*
     * The getAddress() key for the address lookup on the job request form.
     *
     * Same rules as the assistant key above: it belongs in your own
     * config/app_local.php, which is gitignored, and nowhere else. Not app.php,
     * not a template, not a PR, not chat.
     *
     * Sign up at https://getaddress.io and copy the API key from the console.
     * The free plan allows 200 full look-ups a day, which is well beyond
     * anything the demo or the marking will use, and suggestions as the
     * customer types are free on top of that.
     *
     * Leave it empty and the form behaves exactly as it did before the lookup
     * existed: no suggestions appear and the customer types the address by
     * hand. That is the correct setup for anyone who has not been given a key,
     * and it is what the test suite runs with.
     *
     * The endpoint, the Victorian filter and our own rate limits live in
     * app.php.
     */
    'Address' => [
        'apiKey' => '',
    ],
];
