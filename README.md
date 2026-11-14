# Clean Flow Plumbing — Job Management Web App

A full-stack web application for a small plumbing business: customers book jobs online, office staff review and assign them, plumbers manage their schedule, and a manager oversees the team.

Built by a team of six as the industry-experience project for **FIT3047 (Monash University, 2026)**. This repository is my personal portfolio copy of the final codebase.

> Screenshots / demo GIF: _add images to `docs/screenshots/` and link them here._

## Tech stack

- **Backend:** PHP 8.2+, CakePHP 5, CakePHP Authentication, Migrations
- **Database:** MySQL (SQLite for the test suite)
- **Frontend:** Server-rendered CakePHP templates, vanilla JavaScript, CSS
- **Integrations:** Cloudflare Turnstile (bot protection), Google Gemini (help assistant), Photon / G-NAF address lookup
- **Quality:** PHPUnit (40+ test classes), PHPStan, Psalm, PHP_CodeSniffer, GitHub Actions CI

## Features

**Customers**
- Register, sign in and reset a forgotten password
- Submit a job request with live address lookup, business-hours visit times and Turnstile protection
- Track each job through its stages and rate the completed work

**Office staff (admin)**
- Review incoming job requests and contact enquiries
- Assign jobs to plumbers and vehicles, with clash detection
- Combined plumber availability view with alerts
- Compliance checks, audit log, and an editor for public website content with version history

**Plumbers**
- Dashboard of assigned jobs, calendar and availability
- Move a job through the workflow: `Submitted → Reviewing → Approved → Assigned → In progress → Completed / Unable to complete`

**Manager**
- Create, deactivate and reactivate admin accounts
- Job overview and plumber timetables

**Security**
- Login rate limiting per IP, role and account; form rate limiting
- Strong password rules, hashed passwords, constant-time failed sign-ins
- Role-based access control across customer, plumber, admin and manager areas
- Server-side validation of free text, Australian phone numbers and visit times

## My contributions

I was one of four software developers on the team (the others covered cybersecurity and business analysis). My main work:

- **Customer side:** Contact Us page, customer registration and authentication, the job request form (incl. Turnstile), and the customer job status view
- **Job workflow:** the end-to-end job stage workflow, assigning jobs to plumbers, and vehicle management with clash detection
- **Plumber and manager features:** plumber dashboard, calendar and availability; manager job view and plumber timetable
- **Validation and security:** login rate limiting, password strength rules, free-text input rules (length, letter content, repeated-character and URL-only rejection), phone number and business-hours validation, and access-permission fixes

## Running locally

Requirements: PHP 8.2+ (with `intl`, `mbstring`, `pdo_mysql`), Composer, MySQL.

```bash
composer install
cp config/app_local.example.php config/app_local.php   # if composer did not create it
```

Set your database connection in `config/app_local.php` (`Datasources.default`), then:

```bash
bin/cake migrations migrate
bin/cake seed_demo_jobs                       # optional demo data
bin/cake promote_manager ADMIN_EMAIL          # make an existing admin the manager
bin/cake server -p 8765
```

Open http://localhost:8765.

Optional keys (Turnstile, Gemini, address lookup) go in `config/app_local.php`; see `config/app_local.example.php`. Email is written to `logs/email.log` by default, so nothing is sent during development.

## Tests

```bash
vendor/bin/phpunit
composer cs-check
vendor/bin/phpstan analyse
```

## Team and credit

This project was built collaboratively by a six-person team (4 software developers, 1 cybersecurity, 1 business analyst) for FIT3047 at Monash University. Code outside the areas listed under *My contributions* was written by my teammates.
