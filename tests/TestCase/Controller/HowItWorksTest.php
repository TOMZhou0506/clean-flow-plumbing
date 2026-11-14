<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The "How it works" steps on the landing page: a heading and a line of explanation each.
 */
class HowItWorksTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
    ];

    /**
     * @return array<int, array{string, string}> The heading and the explanation of each step.
     */
    private function steps(): array
    {
        $this->get('/');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $this->assertSame(1, preg_match('~<ol class="steps-list">(.*?)</ol>~s', $body, $list));
        preg_match_all(
            '~<strong class="steps-list__title">(.*?)</strong>\s*<span class="steps-list__text">(.*?)</span>~s',
            $list[1],
            $found,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn(array $match): array => [
                trim(html_entity_decode($match[1])),
                trim((string)preg_replace('/\s+/', ' ', html_entity_decode($match[2]))),
            ],
            $found,
        );
    }

    public function testEveryStepHasAHeadingAndAnExplanation(): void
    {
        $steps = $this->steps();

        $this->assertCount(4, $steps);
        $this->assertSame(
            [
                'Log your job online',
                'We run a quick compliance check',
                'Your job is approved and scheduled',
                'Our plumber completes the work',
            ],
            array_column($steps, 0),
        );
        foreach ($steps as [$heading, $explanation]) {
            $this->assertGreaterThan(30, mb_strlen($explanation), $heading);
        }
    }

    public function testTheStepsTellTheCustomerWhatTheyDoAndWhatHappensNext(): void
    {
        $steps = $this->steps();

        $this->assertStringContainsString('Fill in a short form', $steps[0][1]);
        $this->assertStringContainsString('Our team reviews the details', $steps[1][1]);
        $this->assertStringContainsString('follow the progress online', $steps[2][1]);
        $this->assertStringContainsString('rate the visit', $steps[3][1]);
    }

    public function testTheExplanationsAvoidInternalTerms(): void
    {
        // The headings are the client's own wording and stay as they were. The new explanations
        // underneath are written in plain English.
        $text = strtolower(implode(' ', array_column($this->steps(), 1)));

        foreach (['compliance', 'job stage', 'stage', 'checklist'] as $internal) {
            $this->assertStringNotContainsString($internal, $text);
        }
    }

    public function testTheStepsStayInAnOrderedListThatStacksOnAPhone(): void
    {
        $this->get('/');

        $this->assertResponseContains('<ol class="steps-list">');
        $css = (string)file_get_contents(WWW_ROOT . 'css' . DS . 'landing.css');
        // auto-fit with a minimum width: one column on a narrow screen, more when there is room.
        $this->assertStringContainsString('repeat(auto-fit, minmax(min(100%, 24rem), 1fr))', $css);
    }
}
