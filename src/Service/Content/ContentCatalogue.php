<?php
declare(strict_types=1);

namespace App\Service\Content;

use Cake\Core\Configure;

/**
 * The agreed set of public text the office can edit, and nothing else.
 *
 * Every editable item is listed here with its group, label, kind, length limit
 * and default. The default is the wording the site shipped with, so a fresh
 * install (or an item nobody has touched) reads exactly as before, and
 * "Original wording" is always there to go back to.
 *
 * Adding an item means adding it here and calling $this->Content in the
 * template. Nothing outside this list can be saved, whatever is posted.
 */
final class ContentCatalogue
{
    /**
     * A single line of text.
     *
     * @var string
     */
    public const KIND_LINE = 'line';

    /**
     * A paragraph. Line breaks are kept and shown as line breaks.
     *
     * @var string
     */
    public const KIND_TEXT = 'text';

    /**
     * An Australian phone number, also used to build the tel: link.
     *
     * @var string
     */
    public const KIND_PHONE = 'phone';

    /**
     * The groups, in the order the editor lists them.
     *
     * @var array<string, array{label: string, intro: string}>
     */
    public const GROUPS = [
        'landing' => [
            'label' => 'Landing page',
            'intro' => 'The headline, introduction and section headings on the home page.',
        ],
        'services' => [
            'label' => 'Services',
            'intro' => 'The five service cards on the home page. The photos stay as they are.',
        ],
        'faq' => [
            'label' => 'FAQ',
            'intro' => 'Up to six questions. Leave a question empty to hide it from the page.',
        ],
        'contact' => [
            'label' => 'Contact details',
            'intro' => 'The phone number shown across the site, and the introduction on the contact page.',
        ],
    ];

    /**
     * How many FAQ slots the editor offers.
     *
     * @var int
     */
    public const FAQ_SLOTS = 6;

    /**
     * @var array<string, array{group: string, label: string, kind: string, max: int, default: string, help?: string}>|null
     */
    private static ?array $items = null;

    /**
     * Every editable item, keyed by its content key.
     *
     * @return array<string, array{group: string, label: string, kind: string, max: int, default: string, help?: string}>
     */
    public static function items(): array
    {
        if (self::$items !== null) {
            return self::$items;
        }

        $items = [
            // ---- Landing page -------------------------------------------------
            'home.hero.eyebrow' => self::line('landing', 'Hero label', 'Clean Flow Plumbing Services', 60),
            'home.hero.heading' => self::line('landing', 'Hero heading', 'Reliable plumbing, done right.', 80),
            'home.hero.lead' => self::text(
                'landing',
                'Hero introduction',
                'Clean Flow Plumbing Services handles pipe repairs, drainage, hot water installation and leak '
                    . 'detection for homes and businesses across Victoria. Licensed, insured, and easy to reach.',
                400,
            ),
            'home.trust.1' => self::line('landing', 'Trust point 1', 'Licensed & insured', 40),
            'home.trust.2' => self::line('landing', 'Trust point 2', 'Fixed-price quotes', 40),
            'home.trust.3' => self::line('landing', 'Trust point 3', 'Track your job online', 40),
            'home.services.eyebrow' => self::line('landing', 'Services section label', 'What we do', 60),
            'home.services.title' => self::line('landing', 'Services section heading', 'Services', 60),
            'home.services.intro' => self::text(
                'landing',
                'Services section introduction',
                'From a dripping tap to a full hot water changeover, one team looks after it all.',
                300,
            ),
            'home.how.eyebrow' => self::line('landing', 'How it works label', 'Simple from start to finish', 60),
            'home.how.title' => self::line('landing', 'How it works heading', 'How it works', 60),
            'home.how.1.title' => self::line('landing', 'Step 1 heading', 'Log your job online', 80),
            'home.how.1.text' => self::text(
                'landing',
                'Step 1 text',
                'Fill in a short form with your address, what needs fixing and when suits you. '
                    . 'It takes about two minutes.',
                300,
            ),
            'home.how.2.title' => self::line('landing', 'Step 2 heading', 'We run a quick compliance check', 80),
            'home.how.2.text' => self::text(
                'landing',
                'Step 2 text',
                'Our team reviews the details and checks everything is in order for the work.',
                300,
            ),
            'home.how.3.title' => self::line('landing', 'Step 3 heading', 'Your job is approved and scheduled', 80),
            'home.how.3.text' => self::text(
                'landing',
                'Step 3 text',
                'We confirm a plumber and a time, and you can follow the progress online.',
                300,
            ),
            'home.how.4.title' => self::line('landing', 'Step 4 heading', 'Our plumber completes the work', 80),
            'home.how.4.text' => self::text(
                'landing',
                'Step 4 text',
                'Your plumber arrives, does the job and marks it finished. You can then rate the visit.',
                300,
            ),
            'home.faq.eyebrow' => self::line('landing', 'FAQ section label', 'Good to know', 60),
            'home.faq.title' => self::line('landing', 'FAQ section heading', 'FAQ', 60),
            'home.cta.title' => self::line('landing', 'Call to action heading', 'Ready to get started?', 80),
            'home.cta.text' => self::text(
                'landing',
                'Call to action text',
                'Tell us what you need and we will take it from there.',
                200,
            ),
        ];

        // ---- Services ---------------------------------------------------------
        $services = [
            1 => ['Pipe repairs', 'Burst, corroded or leaking pipes fixed quickly and properly.'],
            2 => ['Drainage solutions', 'Blocked drains cleared and drainage that keeps flowing.'],
            3 => ['Hot water installation', 'Supply and installation of reliable hot water systems.'],
            4 => ['Leak detection', 'Find the source of a leak before it becomes costly damage.'],
            5 => ['Routine maintenance', 'Regular checks that catch small problems early.'],
        ];
        foreach ($services as $number => [$title, $text]) {
            $items["service.$number.title"] = self::line('services', "Service $number name", $title, 60);
            $items["service.$number.text"] = self::text('services', "Service $number description", $text, 200);
        }

        // ---- FAQ --------------------------------------------------------------
        $faqs = [
            1 => ['How much will my job cost?', 'We provide a fixed-price quote before any work starts.'],
            2 => ['What areas do you service?', 'We cover Melbourne and regional Victoria.'],
            3 => ['Do I need to call for updates?', 'No — your job status is available online.'],
        ];
        for ($number = 1; $number <= self::FAQ_SLOTS; $number++) {
            [$question, $answer] = $faqs[$number] ?? ['', ''];
            $items["faq.$number.question"] = self::line('faq', "Question $number", $question, 150);
            $items["faq.$number.answer"] = self::text('faq', "Answer $number", $answer, 600);
        }

        // ---- Contact details ---------------------------------------------------
        $items['contact.phone'] = [
            'group' => 'contact',
            'label' => 'Phone number',
            'kind' => self::KIND_PHONE,
            'max' => 20,
            'default' => '(03) 9000 0000',
            'help' => 'Shown in the top menu, on the contact and privacy pages, and by the assistant.',
        ];
        $items['contact.intro'] = self::text(
            'contact',
            'Contact page introduction',
            'If you have any questions or feedback, please fill out the form below.',
            300,
        );

        return self::$items = $items;
    }

    /**
     * @return array{group: string, label: string, kind: string, max: int, default: string, help?: string}|null
     */
    public static function item(string $key): ?array
    {
        return self::items()[$key] ?? null;
    }

    /**
     * The items in one group, in display order.
     *
     * @return array<string, array{group: string, label: string, kind: string, max: int, default: string, help?: string}>
     */
    public static function group(string $group): array
    {
        return array_filter(self::items(), static fn(array $item): bool => $item['group'] === $group);
    }

    /**
     * The roles allowed into the editor. The client nominates these; they are
     * set in config/app.php as Cms.editorRoles.
     *
     * @return list<string>
     */
    public static function editorRoles(): array
    {
        $roles = Configure::read('Cms.editorRoles', ['admin']);

        return array_values(array_filter(array_map('trim', (array)$roles)));
    }

    /**
     * Whether a signed-in office role may edit content.
     */
    public static function canEdit(?string $role): bool
    {
        return $role !== null && in_array($role, self::editorRoles(), true);
    }

    /**
     * The tel: form of a phone number: "(03) 9000 0000" becomes "+61390000000".
     */
    public static function telHref(string $phone): string
    {
        $digits = (string)preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '61' . substr($digits, 1);
        }

        return $digits === '' ? '' : 'tel:+' . $digits;
    }

    /**
     * @return array{group: string, label: string, kind: string, max: int, default: string}
     */
    private static function line(string $group, string $label, string $default, int $max): array
    {
        return ['group' => $group, 'label' => $label, 'kind' => self::KIND_LINE, 'max' => $max, 'default' => $default];
    }

    /**
     * @return array{group: string, label: string, kind: string, max: int, default: string}
     */
    private static function text(string $group, string $label, string $default, int $max): array
    {
        return ['group' => $group, 'label' => $label, 'kind' => self::KIND_TEXT, 'max' => $max, 'default' => $default];
    }
}
