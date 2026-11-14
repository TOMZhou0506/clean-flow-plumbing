<?php
declare(strict_types=1);

namespace App\Test\TestCase\Validation;

use App\Model\Table\JobRequestsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Shared content rules for Contact Message and Job Description.
 */
class PublicDescriptionValidationTest extends TestCase
{
    /**
     * Both public description fields use the same limits and filler checks.
     *
     * @param string $value Submitted description.
     * @param string $contactError Expected contact form error.
     * @return void
     */
    #[DataProvider('invalidDescriptions')]
    public function testObviousFillerIsRejectedByBothFields(string $value, string $contactError): void
    {
        $contact = TableRegistry::getTableLocator()->get('ContactSubmissions')
            ->newEntity(['message' => $value]);
        $jobRequest = TableRegistry::getTableLocator()->get('JobRequests')
            ->newEntity(['job_description' => $value]);

        $this->assertContains(
            $contactError,
            array_values($contact->getError('message')),
        );
        $this->assertNotSame([], $jobRequest->getError('job_description'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDescriptions(): iterable
    {
        $filler = 'Please write a message with letters, not just repeated characters or a link.';
        yield 'too short' => ['tap', 'Please enter at least 5 characters.'];
        yield 'numbers only' => ['111111111111111111', $filler];
        yield 'one repeated letter' => ['aaaaaaaaaaaaa', $filler];
        yield 'repeated with spaces' => ['a a a a a', $filler];
        yield 'one letter padded with punctuation' => ['a!!!!', $filler];
        yield 'punctuation only' => ['!!!!!!!!!!!!', $filler];
        yield 'email only' => ['spam@example.com', $filler];
        yield 'URL only' => ['https://example.com', $filler];
        yield 'more than 500 characters' => [
            str_repeat('A leaking pipe needs repair. ', 20),
            'Please keep your message to 500 characters or fewer.',
        ];
    }

    /**
     * @return void
     */
    #[DataProvider('acceptedDescriptions')]
    public function testNormalEnglishDescriptionsAreAcceptedByBothFields(string $value): void
    {
        $contact = TableRegistry::getTableLocator()->get('ContactSubmissions')
            ->newEntity(['message' => $value]);
        $jobRequest = TableRegistry::getTableLocator()->get('JobRequests')
            ->newEntity(['job_description' => $value]);

        $this->assertSame([], $contact->getError('message'));
        $this->assertSame([], $jobRequest->getError('job_description'));
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedDescriptions(): iterable
    {
        yield 'full sentence' => ['The kitchen tap is leaking under the sink.'];
        yield 'short emergency' => ['SOS!!!'];
        yield 'short request' => ['Leak!'];
        yield 'two words' => ['No water'];
    }

    /**
     * Each problem with the job description gets its own message, and only that one.
     *
     * @param string|null $value Submitted description.
     * @param string $expected The one message that should appear.
     * @return void
     */
    #[DataProvider('jobDescriptionProblems')]
    public function testEachJobDescriptionProblemHasItsOwnMessage(?string $value, string $expected): void
    {
        $jobRequest = TableRegistry::getTableLocator()->get('JobRequests')
            ->newEntity(['job_description' => $value]);

        $this->assertSame([$expected], array_values($jobRequest->getError('job_description')));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function jobDescriptionProblems(): iterable
    {
        yield 'empty' => ['', JobRequestsTable::DESCRIPTION_EMPTY];
        yield 'only spaces' => ['       ', JobRequestsTable::DESCRIPTION_EMPTY];
        yield 'too short' => ['tap', JobRequestsTable::DESCRIPTION_TOO_SHORT];
        yield 'too long' => [str_repeat('A leaking pipe needs repair. ', 20), JobRequestsTable::DESCRIPTION_TOO_LONG];
        yield 'numbers only' => ['111111111111111111', JobRequestsTable::DESCRIPTION_NOT_MEANINGFUL];
        yield 'one repeated letter' => ['aaaaaaaaaaaaa', JobRequestsTable::DESCRIPTION_NOT_MEANINGFUL];
        yield 'email only' => ['spam@example.com', JobRequestsTable::DESCRIPTION_NOT_MEANINGFUL];
    }

    /**
     * @return void
     */
    public function testAMissingJobDescriptionIsToldToDescribeTheWork(): void
    {
        $jobRequest = TableRegistry::getTableLocator()->get('JobRequests')->newEntity([]);

        $this->assertSame(
            [JobRequestsTable::DESCRIPTION_EMPTY],
            array_values($jobRequest->getError('job_description')),
        );
    }

    /**
     * The old catch-all sentence is gone.
     *
     * @return void
     */
    public function testTheOldCatchAllSentenceIsGone(): void
    {
        foreach (['/src/Model/Table/JobRequestsTable.php', '/templates/JobRequests/add.php'] as $file) {
            $this->assertStringNotContainsString(
                'valid job description in English',
                (string)file_get_contents(ROOT . $file),
                $file,
            );
        }
    }
}
