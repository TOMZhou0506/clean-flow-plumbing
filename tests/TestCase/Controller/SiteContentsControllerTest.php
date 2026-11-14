<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The website content editor, one test or more per acceptance criterion:
 * the agreed content, editing without a developer, changes live at once,
 * nominated roles only, escaped output, the audit trail, and restoring an
 * earlier version.
 */
class SiteContentsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.Customers',
        'app.SiteContents',
        'app.SiteContentVersions',
    ];

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('Cms.editorRoles', ['admin']);
    }

    private function signIn(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id,
            'admin_username' => match ($id) {
                1 => 'hannah',
                2 => 'staffuser',
                default => 'manageruser',
            },
            'role' => $role,
        ]]);
    }

    /**
     * Post some fields of one group, keyed by content key.
     *
     * @param array<string, string> $values
     */
    private function save(string $group, array $values): void
    {
        $content = [];
        foreach ($values as $key => $value) {
            $content[str_replace('.', '__', $key)] = $value;
        }
        $this->enableCsrfToken();
        $this->post('/website-content/' . $group, ['content' => $content]);
    }

    public function testThePublicPagesShowTheOriginalWordingUntilSomebodyEditsIt(): void
    {
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseContains('Reliable plumbing, done right.');
        $this->assertResponseContains('Pipe repairs');
        $this->assertResponseContains('How much will my job cost?');
        $this->assertResponseContains('Our plumber completes the work');
        $this->assertResponseContains('href="tel:+61390000000">(03) 9000 0000</a>');

        $this->get('/contacts/add');
        $this->assertResponseContains('If you have any questions or feedback, please fill out the form below.');
    }

    public function testAnAdminSavesAChangeAndTheHomePageShowsItStraightAway(): void
    {
        $this->signIn(1, 'admin');
        $this->save('landing', ['home.hero.heading' => 'Plumbing you can count on.']);

        $this->assertRedirect('/website-content/landing');
        $this->assertFlashMessage('Saved 1 change. It is live on the site now.');

        $this->get('/');
        $this->assertResponseContains('Plumbing you can count on.');
        $this->assertResponseNotContains('Reliable plumbing, done right.');
    }

    public function testServicesFaqAndContactDetailsAreEditableToo(): void
    {
        $this->signIn(1, 'admin');
        $this->save('services', ['service.2.title' => 'Blocked drains', 'service.2.text' => 'Cleared the same day.']);
        $this->save('faq', [
            'faq.1.question' => '',
            'faq.1.answer' => '',
            'faq.4.question' => 'Do you work weekends?',
            'faq.4.answer' => "Emergencies only.\nCall us first.",
        ]);
        $this->save('contact', ['contact.phone' => '(03) 9123 4567', 'contact.intro' => 'Ask us anything.']);

        $this->get('/');
        $this->assertResponseContains('Blocked drains');
        $this->assertResponseContains('Cleared the same day.');
        $this->assertResponseNotContains('How much will my job cost?', 'An emptied FAQ is hidden.');
        $this->assertResponseContains('Do you work weekends?');
        $this->assertResponseContains('Emergencies only.<br>');
        $this->assertResponseContains('href="tel:+61391234567">(03) 9123 4567</a>');

        $this->get('/contacts/add');
        $this->assertResponseContains('Ask us anything.');
        $this->assertResponseContains('(03) 9123 4567');

        $this->get('/privacy');
        $this->assertResponseContains('(03) 9123 4567');
    }

    public function testMarkupAnEditorTypesIsShownAsTextAndNeverRuns(): void
    {
        $this->signIn(1, 'admin');
        $this->save('landing', [
            'home.hero.heading' => '<script>alert(1)</script>',
            'home.hero.lead' => '<img src=x onerror=alert(2)> "quoted" & more',
        ]);

        $this->get('/');
        $this->assertResponseContains('&lt;script&gt;alert(1)&lt;/script&gt;');
        $this->assertResponseNotContains('<script>alert(1)');
        $this->assertResponseContains('&lt;img src=x onerror=alert(2)&gt;');
        $this->assertResponseNotContains('<img src=x');
    }

    public function testOnlyTheNominatedRolesReachTheEditorOrSeeItInTheMenu(): void
    {
        $this->get('/website-content');
        $this->assertRedirectContains('/login');

        foreach ([[2, 'staff'], [3, 'manager']] as [$id, $role]) {
            $this->signIn($id, $role);
            $this->get('/website-content');
            $this->assertResponseCode(403, $role . ' should be refused');
            $this->enableCsrfToken();
            $this->post('/website-content/landing', ['content' => ['home__hero__heading' => 'Hacked']]);
            $this->assertResponseCode(403);
        }
        $this->get('/');
        $this->assertResponseNotContains('Hacked');

        $this->signIn(3, 'manager');
        $this->get('/plumbers');
        $this->assertResponseNotContains('Website content');

        $this->signIn(1, 'admin');
        $this->get('/website-content');
        $this->assertResponseOk();
        $this->get('/job-requests/manage');
        $this->assertResponseContains('Website content');

        // The client can nominate another role without a code change.
        Configure::write('Cms.editorRoles', ['admin', 'manager']);
        $this->signIn(3, 'manager');
        $this->get('/website-content');
        $this->assertResponseOk();
        $this->get('/plumbers');
        $this->assertResponseContains('Website content');
    }

    public function testEveryChangeIsInTheAuditTrailWithWhoAndWhen(): void
    {
        $this->signIn(1, 'admin');
        $this->save('landing', ['home.hero.heading' => 'New heading', 'home.cta.title' => 'Call us today']);

        $entries = TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'content.updated'])
            ->orderBy(['entity_id' => 'ASC'])
            ->all()
            ->toList();
        $this->assertCount(2, $entries);
        $this->assertSame(['home.cta.title', 'home.hero.heading'], array_map(
            static fn($entry): string => (string)$entry->entity_id,
            $entries,
        ));
        foreach ($entries as $entry) {
            $this->assertSame(1, (int)$entry->user_id);
            $this->assertSame('site_content', $entry->entity_type);
            $this->assertNotNull($entry->created);
        }
    }

    public function testSavingWithoutChangesWritesNothing(): void
    {
        $this->signIn(1, 'admin');
        $this->save('landing', ['home.hero.heading' => 'Reliable plumbing, done right.']);

        $this->assertFlashMessage('Nothing had changed, so nothing was saved.');
        $this->assertSame(0, TableRegistry::getTableLocator()->get('SiteContentVersions')->find()->count());
        $this->assertSame(0, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'content.updated'])->count());
    }

    public function testAnEarlierVersionAndTheOriginalWordingCanBeRestored(): void
    {
        $this->signIn(1, 'admin');
        $this->save('landing', ['home.hero.heading' => 'First try']);
        $this->save('landing', ['home.hero.heading' => 'Second try']);

        $versions = TableRegistry::getTableLocator()->get('SiteContentVersions');
        $first = $versions->find()->where(['value' => 'First try'])->firstOrFail();

        $this->get('/website-content/history?key=home.hero.heading');
        $this->assertResponseOk();
        $this->assertResponseContains('First try');
        $this->assertResponseContains('Second try');
        $this->assertResponseContains('Original wording');

        $this->enableCsrfToken();
        $this->post('/website-content/restore', ['key' => 'home.hero.heading', 'version' => (string)$first->id]);
        $this->assertRedirect('/website-content/history?key=home.hero.heading');
        $this->get('/');
        $this->assertResponseContains('First try');

        $this->enableCsrfToken();
        $this->post('/website-content/restore', ['key' => 'home.hero.heading', 'version' => 'default']);
        $this->get('/');
        $this->assertResponseContains('Reliable plumbing, done right.');

        // Restoring adds to the history rather than rewriting it, and is audited.
        $this->assertSame(4, $versions->find()->where(['content_key' => 'home.hero.heading'])->count());
        $this->assertSame(2, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'content.restored', 'user_id' => 1])->count());
    }

    public function testAVersionOfOneItemCannotBeRestoredIntoAnother(): void
    {
        $this->signIn(1, 'admin');
        $this->save('landing', ['home.hero.heading' => 'Heading text']);
        $version = TableRegistry::getTableLocator()->get('SiteContentVersions')->find()->firstOrFail();

        $this->enableCsrfToken();
        $this->post('/website-content/restore', ['key' => 'home.cta.title', 'version' => (string)$version->id]);
        $this->assertResponseCode(404);

        $this->get('/website-content/restore');
        $this->assertResponseCode(405);
    }

    public function testInvalidTextIsRefusedAndNothingInTheGroupIsSaved(): void
    {
        $this->signIn(1, 'admin');
        $this->save('landing', [
            'home.hero.heading' => '',
            'home.hero.eyebrow' => str_repeat('x', 61),
            'home.cta.title' => 'This one is fine',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('This cannot be empty.');
        $this->assertResponseContains('Keep this to 60 characters or fewer.');

        $this->save('contact', ['contact.phone' => 'call me maybe']);
        $this->assertResponseContains('Enter a phone number such as (03) 9000 0000.');

        $this->save('faq', ['faq.5.question' => 'A question with no answer?', 'faq.5.answer' => '']);
        $this->assertResponseContains('Add an answer, or clear the question to hide it.');

        $this->assertSame(0, TableRegistry::getTableLocator()->get('SiteContents')->find()->count());
        $this->get('/');
        $this->assertResponseNotContains('This one is fine');
    }

    public function testOnlyTheAgreedItemsCanBeWritten(): void
    {
        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->post('/website-content/landing', ['content' => [
            'admin__password' => 'nope',
            'service__1__title' => 'Belongs to another group',
        ]]);

        $this->assertSame(0, TableRegistry::getTableLocator()->get('SiteContents')->find()->count());

        $this->get('/website-content/history?key=admin.password');
        $this->assertResponseCode(404);
        $this->get('/website-content/nonsense');
        $this->assertResponseCode(404);
    }

    public function testTheEditorPagesRender(): void
    {
        $this->signIn(1, 'admin');

        $this->get('/website-content');
        $this->assertResponseOk();
        foreach (['Landing page', 'Services', 'FAQ', 'Contact details'] as $group) {
            $this->assertResponseContains($group);
        }

        foreach (['landing', 'services', 'faq', 'contact'] as $group) {
            $this->get('/website-content/' . $group);
            $this->assertResponseOk($group);
            $this->assertResponseContains('Save changes');
        }
        $this->assertResponseContains('name="content[contact__phone]"');
    }
}
