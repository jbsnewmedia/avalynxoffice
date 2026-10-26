<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Tests\Support\AllowAllLicenseEnforcement;
use AvalynxOffice\Controller\AccountAdminController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AccountAdminControllerUnitTest extends TestCase
{
    private function createController(): AccountAdminController
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);

        return new AccountAdminController($translator, new AllowAllLicenseEnforcement(), $csrfTokenManager, $this->createStub(\AvalynxOffice\Repository\AccountRepository::class));
    }

    /**
     * @param int|string|null $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('textareaValueProvider')]
    public function testGetTextareaValueNormalizesFieldData(int|string|null $data, ?string $expected): void
    {
        $controller = $this->createController();

        $field = $this->createStub(FormInterface::class);
        $field->method('getData')->willReturn($data);

        $form = $this->createStub(FormInterface::class);
        $form->method('get')->willReturn($field);

        $method = new \ReflectionMethod(AccountAdminController::class, 'getTextareaValue');

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

    public function testGetUsersValueFiltersNonUserEntriesAndHandlesNonIterableData(): void
    {
        $controller = $this->createController();

        $method = new \ReflectionMethod(AccountAdminController::class, 'getUsersValue');

        $field = $this->createStub(FormInterface::class);
        $field->method('getData')->willReturn(null);

        $form = $this->createStub(FormInterface::class);
        $form->method('get')->willReturn($field);

        self::assertSame([], $method->invoke($controller, $form));

        $user = new \AvalynxOffice\Entity\User();
        $user->setEmail('user@example.com');
        $field = $this->createStub(FormInterface::class);
        $field->method('getData')->willReturn([$user, 'nope']);

        $form = $this->createStub(FormInterface::class);
        $form->method('get')->willReturn($field);

        self::assertSame([$user], $method->invoke($controller, $form));
    }
}
