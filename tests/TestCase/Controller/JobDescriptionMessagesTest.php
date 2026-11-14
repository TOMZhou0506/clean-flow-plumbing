<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Table\JobRequestsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * What a customer sees when the job description is wrong.
 */
class JobDescriptionMessagesTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Customers',
        'app.JobRequests',
    ];

    private function signIn(): void
    {
        $this->session(['CustomerAuth' => [
            'cust_id' => 1,
            'cust_email' => 'kash@example.com',
            'cust_fname' => 'Kash',
            'cust_lname' => 'Patel',
        ]]);
    }

    private function submit(string $description): void
    {
        $this->signIn();
        $this->enableCsrfToken();
        $this->post('/job-requests/add', [
            'street_address' => '8 Test Avenue',
            'suburb' => 'Clayton',
            'state' => 'VIC',
            'postcode' => '3168',
            'job_description' => $description,
            'preferred_datetime' => '2030-11-20T14:30',
            'contact_phone' => '0412 345 678',
        ]);
    }

    public function testEachProblemShowsItsOwnMessageNextToTheField(): void
    {
        $cases = [
            'empty' => ['', JobRequestsTable::DESCRIPTION_EMPTY],
            'too short' => ['tap', JobRequestsTable::DESCRIPTION_TOO_SHORT],
            'too long' => [str_repeat('A leaking pipe needs repair. ', 20), JobRequestsTable::DESCRIPTION_TOO_LONG],
            'not meaningful' => ['11111111111111', JobRequestsTable::DESCRIPTION_NOT_MEANINGFUL],
        ];

        foreach ($cases as $label => [$description, $message]) {
            $this->cleanup();
            $this->submit($description);

            $this->assertResponseOk($label);
            // The one message, and nothing else, among the messages shown under the fields.
            preg_match_all('/<div class="error-message"[^>]*>(.*?)<\/div>/s', (string)$this->_response->getBody(), $found);
            $this->assertSame([h($message)], $found[1], $label);
            $this->assertResponseNotContains('valid job description in English', $label);
            $this->assertSame(
                0,
                TableRegistry::getTableLocator()->get('JobRequests')->find()
                    ->where(['street_address' => '8 Test Avenue'])->count(),
                $label,
            );
        }
    }

    public function testTheMessageIsLinkedToTheFieldAndWhatWasTypedIsKept(): void
    {
        $this->submit('tap');

        $body = (string)$this->_response->getBody();
        // The error sits inside the field's own block, right under the textarea.
        $this->assertMatchesRegularExpression(
            '/<div class="input textarea required error">.*?<textarea[^>]*name="job_description"[^>]*>tap<\/textarea>.*?'
            . '<div class="error-message"[^>]*>' . preg_quote(h(JobRequestsTable::DESCRIPTION_TOO_SHORT), '/') . '/s',
            $body,
        );
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*aria-describedby="[^"]+"[^>]*name="job_description"|'
            . '<textarea[^>]*name="job_description"[^>]*aria-describedby="[^"]+"/s',
            $body,
        );
        $this->assertMatchesRegularExpression('/<div class="error-message" id="[^"]+"/', $body);
    }

    public function testThePageCarriesTheSameWordingForTheBrowserChecks(): void
    {
        $this->signIn();

        $this->get('/job-requests/add');

        $this->assertResponseOk();
        $this->assertResponseContains('data-validity-message="' . h(JobRequestsTable::DESCRIPTION_EMPTY) . '"');
        $this->assertResponseContains('data-short-message="' . h(JobRequestsTable::DESCRIPTION_TOO_SHORT) . '"');
        $this->assertResponseContains('data-meaningful-message="' . h(JobRequestsTable::DESCRIPTION_NOT_MEANINGFUL) . '"');
        $this->assertResponseContains('data-char-counter');
    }
}
