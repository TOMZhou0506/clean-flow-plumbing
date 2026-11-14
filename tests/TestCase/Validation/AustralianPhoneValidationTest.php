<?php
declare(strict_types=1);

namespace App\Test\TestCase\Validation;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Australian 03 landline and 04 mobile formats shared by public forms.
 */
class AustralianPhoneValidationTest extends TestCase
{
    #[DataProvider('invalidPhoneNumbers')]
    public function testInvalidNumbersAreRejectedByEveryPublicForm(string $number): void
    {
        $contact = TableRegistry::getTableLocator()->get('ContactSubmissions')
            ->newEntity(['phone' => $number]);
        $jobRequest = TableRegistry::getTableLocator()->get('JobRequests')
            ->newEntity(['contact_phone' => $number]);
        $customer = TableRegistry::getTableLocator()->get('Customers')
            ->newEntity(['cust_phone' => $number]);

        $this->assertNotSame([], $contact->getError('phone'));
        $this->assertNotSame([], $jobRequest->getError('contact_phone'));
        $this->assertNotSame([], $customer->getError('cust_phone'));
    }

    public static function invalidPhoneNumbers(): iterable
    {
        yield 'letters' => ['abcd efgh ijk'];
        yield 'other area code' => ['02 9123 4567'];
        yield 'missing spaces' => ['0412345678'];
        yield 'too short' => ['0412 345'];
        yield 'wrong 03 spacing' => ['0391 234 567'];
        yield 'international format' => ['+61 412 345 678'];
    }

    #[DataProvider('acceptedPhoneNumbers')]
    public function testFormattedPhoneIsAcceptedByEveryPublicForm(string $number): void
    {
        $contact = TableRegistry::getTableLocator()->get('ContactSubmissions')
            ->newEntity(['phone' => $number]);
        $jobRequest = TableRegistry::getTableLocator()->get('JobRequests')
            ->newEntity(['contact_phone' => $number]);
        $customer = TableRegistry::getTableLocator()->get('Customers')
            ->newEntity(['cust_phone' => $number]);

        $this->assertSame([], $contact->getError('phone'));
        $this->assertSame([], $jobRequest->getError('contact_phone'));
        $this->assertSame([], $customer->getError('cust_phone'));
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedPhoneNumbers(): iterable
    {
        yield '04 mobile' => ['0412 345 678'];
        yield '03 landline' => ['03 9123 4567'];
    }

    public function testOptionalPhoneFieldsMayRemainEmpty(): void
    {
        $contact = TableRegistry::getTableLocator()->get('ContactSubmissions')
            ->newEntity(['phone' => '']);
        $customer = TableRegistry::getTableLocator()->get('Customers')
            ->newEntity(['cust_phone' => '']);

        $this->assertSame([], $contact->getError('phone'));
        $this->assertSame([], $customer->getError('cust_phone'));
    }
}
