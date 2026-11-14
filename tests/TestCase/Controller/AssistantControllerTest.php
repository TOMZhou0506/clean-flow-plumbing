<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\AssistantController;
use App\Service\Assistant\Assistant;
use App\Service\Assistant\KnowledgeBase;
use App\Test\TestCase\Service\Assistant\RecordingClient;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The assistant endpoint.
 *
 * @link \App\Controller\AssistantController
 */
class AssistantControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.Customers',
        'app.JobRequests',
        'app.FormRateLimits',
        'app.AssistantUnansweredQuestions',
    ];

    /**
     * One public entry is enough here. Proving that audiences are isolated is
     * AssistantTest's job; this class is about the endpoint around it.
     *
     * @var list<array<string, mixed>>
     */
    private const ENTRIES = [
        [
            'id' => 'job-request',
            'audience' => ['public', 'customer'],
            'title' => 'Sending a job request',
            'keywords' => ['job request', 'request service', 'book a plumber'],
            'answer' => 'Use the Request service button to open the form.',
            'link' => ['label' => 'Request service', 'url' => '/job-requests/add'],
        ],
    ];

    /**
     * @var \App\Test\TestCase\Service\Assistant\RecordingClient
     */
    private RecordingClient $client;

    /**
     * Swaps the real model for the recording stub, once.
     *
     * Registered here rather than per test so repeated requests in one test do
     * not stack up listeners. The stub is read through $this at call time, so a
     * test can still change what the model "says" before asking.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->client = new RecordingClient('Here is how that works.');

        EventManager::instance()->on('Controller.initialize', function (EventInterface $event): void {
            $controller = $event->getSubject();
            if ($controller instanceof AssistantController) {
                $controller->setAssistant(new Assistant(
                    new KnowledgeBase(self::ENTRIES),
                    $this->client,
                ));
            }
        });
    }

    /**
     * @param string $question The question to post.
     * @return void
     */
    private function ask(string $question): void
    {
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/assistant/ask', ['question' => $question]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $body = json_decode((string)$this->_response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * A signed-out visitor can use it. That is the point of US 10.1: help
     * without contacting staff, and without having to register first either.
     *
     * @return void
     */
    public function testASignedOutVisitorGetsAnAnswer(): void
    {
        $this->client = new RecordingClient('Use the Request service button.');
        $this->ask('how do I send a job request');

        $this->assertResponseOk();
        $payload = $this->payload();
        $this->assertTrue($payload['answered']);
        $this->assertSame('Use the Request service button.', $payload['answer']);
        $this->assertSame([['label' => 'Request service', 'url' => '/job-requests/add']], $payload['links']);
    }

    /**
     * An unanswerable question still returns 200 with a readable reply.
     *
     * US 10.1 requires that an unsupported question cannot make the page fail,
     * and a widget that has to interpret several status codes has several ways
     * to break.
     *
     * @return void
     */
    public function testAnUnsupportedQuestionDoesNotFail(): void
    {
        $this->ask('what is the airspeed velocity of an unladen swallow');

        $this->assertResponseOk();
        $payload = $this->payload();
        $this->assertFalse($payload['answered']);
        $this->assertNotSame('', $payload['answer']);
        $this->assertSame([], $this->client->calls, 'An ungrounded question must not reach the model.');
    }

    /**
     * Only the failures are stored, and only once per distinct question.
     *
     * @return void
     */
    public function testOnlyUnansweredQuestionsAreLogged(): void
    {
        $gaps = TableRegistry::getTableLocator()->get('AssistantUnansweredQuestions');

        $this->ask('how do I send a job request');
        $this->assertSame(0, $gaps->find()->count(), 'An answered question must not be stored.');

        $this->ask('do you sell washing machines');
        $this->assertSame(1, $gaps->find()->count());

        // The same gap again, in different case and spacing.
        $this->ask('Do you   sell washing machines');
        $this->assertSame(1, $gaps->find()->count(), 'The same gap must not create a second row.');
        $this->assertSame(2, (int)$gaps->find()->firstOrFail()->times_asked);
    }

    /**
     * An empty box is somebody pressing enter, not a gap in our knowledge.
     *
     * @return void
     */
    public function testAnEmptyQuestionIsNotLogged(): void
    {
        $this->ask('    ');

        $this->assertResponseOk();
        $this->assertSame(
            0,
            TableRegistry::getTableLocator()->get('AssistantUnansweredQuestions')->find()->count(),
        );
    }

    /**
     * Posting a role alongside the question must change nothing.
     *
     * The audience is read from the session. Without that, the whole access
     * model would be a suggestion the browser could decline.
     *
     * @return void
     */
    public function testAPostedRoleIsIgnored(): void
    {
        $this->enableCsrfToken();
        $this->post('/assistant/ask', [
            'question' => 'how do I send a job request',
            'audience' => 'manager',
            'role' => 'manager',
        ]);

        $this->assertResponseOk();
        $this->assertCount(1, $this->client->calls);
        $this->assertStringContainsString('Request service', $this->client->calls[0]['context']);
    }

    /**
     * Office accounts are refused, and never reach the model.
     *
     * The widget is not rendered for them either, but that is tidiness. This is
     * the control: the URL exists and anybody signed in can post to it.
     *
     * @return void
     */
    public function testOfficeAccountsAreRefused(): void
    {
        foreach (
            [['admin_id' => 1, 'role' => 'admin'],
                  ['admin_id' => 3, 'role' => 'manager'],
                  ['admin_id' => 2, 'role' => 'staff']] as $session
        ) {
            $this->session(['AdminAuth' => $session + ['admin_username' => 'whoever']]);
            $this->ask('how do I send a job request');

            $this->assertResponseCode(403);
            $this->assertSame([], $this->client->calls, 'An office account must not reach the model.');
        }
    }

    /**
     * And the widget is not put in front of them.
     *
     * @return void
     */
    public function testTheWidgetIsNotRenderedForOfficeAccounts(): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => 1,
            'admin_username' => 'hannah',
            'role' => 'admin',
        ]]);
        $this->get('/contact-submissions');

        $this->assertResponseOk();
        $this->assertResponseNotContains('data-assistant');
        $this->assertResponseNotContains('assistant.js');
    }

    /**
     * A signed-out visitor still gets it, so the rule above is a restriction
     * rather than the feature being switched off.
     *
     * @return void
     */
    public function testTheWidgetIsRenderedForVisitors(): void
    {
        $this->get('/contacts/add');

        $this->assertResponseOk();
        $this->assertResponseContains('data-assistant');
    }

    /**
     * A GET cannot trigger a paid call.
     *
     * The route is POST only, so a GET falls through to fallbacks() and reaches
     * the action, where allowMethod() refuses it with a 405. Either way the
     * model is never called, which is the part that matters for an endpoint
     * that costs money.
     *
     * @return void
     */
    public function testAGetCannotReachTheModel(): void
    {
        Configure::write('debug', false);
        $this->get('/assistant/ask');

        $this->assertResponseCode(405);
        $this->assertSame([], $this->client->calls);
    }

    /**
     * The kill switch answers like a quiet assistant, not a broken one.
     *
     * @return void
     */
    public function testTheKillSwitchStopsTheModelBeingCalled(): void
    {
        Configure::write('Assistant.enabled', false);
        $this->ask('how do I send a job request');

        $this->assertResponseOk();
        $this->assertFalse($this->payload()['answered']);
        $this->assertSame([], $this->client->calls);
    }

    /**
     * Too many questions from one address gets a 429 rather than an unbounded
     * spend.
     *
     * @return void
     */
    public function testRepeatedAsksAreRateLimited(): void
    {
        Configure::write('Assistant.rateLimit.limit', 3);
        Configure::write('Assistant.rateLimit.window', 300);

        for ($i = 0; $i < 3; $i++) {
            $this->ask('how do I send a job request number ' . $i);
            $this->assertResponseOk();
        }

        $callsBefore = count($this->client->calls);
        $this->ask('one question too many');

        $this->assertResponseCode(429);
        $this->assertNotSame('', $this->_response->getHeaderLine('Retry-After'));
        $this->assertCount(
            $callsBefore,
            $this->client->calls,
            'A throttled request must not reach the model.',
        );
    }

    /**
     * Whatever the model returns is delivered as JSON data, not as markup.
     *
     * Together with the widget writing it through textContent, a model reply
     * containing a script tag ends up as text on the page rather than a tag in
     * the document.
     *
     * @return void
     */
    public function testAModelReplyContainingMarkupStaysData(): void
    {
        $this->client = new RecordingClient('<script>alert(1)</script> and <b>bold</b>');
        $this->ask('how do I send a job request');

        $this->assertResponseOk();
        $this->assertStringContainsString('application/json', $this->_response->getHeaderLine('Content-Type'));

        // Two halves. The data survives decoding, so the assistant's reply is
        // whatever the model actually said. And the raw body contains no angle
        // bracket at all, because JSON_HEX_TAG turned every one into a unicode
        // escape, so the body cannot close a script element however it is later
        // embedded. Asserting on the absence of the character is a stronger
        // claim than hunting for one particular tag.
        $this->assertStringContainsString('<script>', $this->payload()['answer']);
        $this->assertStringNotContainsString('<', (string)$this->_response->getBody());
        $this->assertStringNotContainsString('>', (string)$this->_response->getBody());
    }

    /**
     * The assistant has no write access, so asking it to book something changes
     * nothing. US 10.2 is explicit that it must not submit or alter a request.
     *
     * @return void
     */
    public function testAskingItToSubmitAJobRequestCreatesNothing(): void
    {
        $this->client = new RecordingClient('I cannot submit that for you.');
        $jobRequests = TableRegistry::getTableLocator()->get('JobRequests');
        $before = $jobRequests->find()->count();

        $this->ask('please book a plumber for me at 12 Clayton Road tomorrow');

        $this->assertResponseOk();
        $this->assertSame($before, $jobRequests->find()->count());
    }
}
