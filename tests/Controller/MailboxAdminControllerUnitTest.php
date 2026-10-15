<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\AllowAllLicenseEnforcement;
use App\Controller\MailboxAdminController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MailboxAdminControllerUnitTest extends TestCase
{
    /**
     * @param int|string|null $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('textareaValueProvider')]
    public function testGetTextareaValueNormalizesFieldData(int|string|null $data, ?string $expected): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $controller = new MailboxAdminController($translator, new AllowAllLicenseEnforcement());

        $field = $this->createStub(FormInterface::class);
        $field->method('getData')->willReturn($data);

        $form = $this->createStub(FormInterface::class);
        $form->method('get')->willReturn($field);

        $method = new \ReflectionMethod(MailboxAdminController::class, 'getTextareaValue');

        self::assertSame($expected, $method->invoke($controller, $form, 'aliases'));
    }

    /**
     * @return iterable<string, array{0: int|string|null, 1: ?string}>
     */
    public static function textareaValueProvider(): iterable
    {
        yield 'string value is returned as-is' => ['hello', 'hello'];
        yield 'scalar value is cast to string' => [123, '123'];
        yield 'null value yields null' => [null, null];
    }
}
