<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The "mark unavailable time" form on the plumber's timetable: short inputs for short values.
 */
class AvailabilityFormLayoutTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
        'app.PlumberUnavailabilities',
    ];

    private function css(string $file): string
    {
        return (string)file_get_contents(WWW_ROOT . 'css' . DS . $file);
    }

    public function testTheDateAndTimeInputsHaveOneShortWidth(): void
    {
        $css = $this->css('plumber-availability.css');

        $this->assertMatchesRegularExpression(
            "/#availability-form input\\[type='date'\\],\\s*#availability-form input\\[type='time'\\]\\s*\\{[^}]*"
            . 'width:\\s*100%;[^}]*max-width:\\s*18rem/s',
            $css,
        );
        $this->assertStringContainsString('repeat(2, minmax(0, 18rem))', $css);
    }

    public function testTimeInputsGetTheSameStyleAsEveryOtherInput(): void
    {
        $css = $this->css('cake.css');

        $this->assertStringContainsString("input[type='time'],", $css);
        $this->assertStringContainsString("input[type='time']:focus", $css);
    }

    public function testTheSubmittedFieldsAndTheirFormatAreUnchanged(): void
    {
        $this->session(['AdminAuth' => ['admin_id' => 2, 'admin_username' => 'staffuser', 'role' => 'staff']]);

        $this->get('/plumbers/availability');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $this->assertMatchesRegularExpression('/<input type="date" name="unavailable_date"[^>]*min="\d{4}-\d{2}-\d{2}"/', $body);
        $this->assertMatchesRegularExpression('/<input type="time" name="start_time"[^>]*min="08:00"[^>]*max="17:00"/', $body);
        $this->assertMatchesRegularExpression('/<input type="time" name="end_time"[^>]*min="08:00"[^>]*max="17:00"/', $body);
        $this->assertResponseContains('id="availability-form"');
    }
}
