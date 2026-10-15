<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DataController;
use App\Entity\Address;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Service\DataService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class DataControllerTest extends TestCase
{
    public function testAllReturnsJsonResponseWithoutDoubleEncoding(): void
    {
        $dataService = $this->createMock(DataService::class);
        $dataService->expects(self::once())
            ->method('getAllData')
            ->willReturn(['mailboxes' => [['id' => 1]]]);

        $response = (new DataController())->all($dataService);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"mailboxes":[{"id":1}]}', (string) $response->getContent());
    }

    public function testAddressSuggestionsFormatsNamedAndUnnamedAddresses(): void
    {
        $controller = new DataController();
        $controller->setContainer(new ServiceLocator([
            'security.token_storage' => fn (): TokenStorageInterface => $this->createTokenStorage(new User()),
        ]));

        $request = Request::create('/data/address-suggestions?search=sup');
        $repository = $this->createMock(AddressRepository::class);
        $repository->expects(self::once())
            ->method('findForAutocomplete')
            ->with('sup')
            ->willReturn([
                (new Address())->setEmail('named@example.com')->setName('Named User'),
                (new Address())->setEmail('plain@example.com'),
            ]);

        $response = $controller->addressSuggestions($request, $repository);

        self::assertJsonStringEqualsJsonString(
            '[{"key":"named@example.com","value":"Named User <named@example.com>"},{"key":"plain@example.com","value":"plain@example.com"}]',
            (string) $response->getContent(),
        );
    }

    public function testAddressSuggestionsRequiresAuthenticatedUser(): void
    {
        $controller = new DataController();
        $controller->setContainer(new ServiceLocator([
            'security.token_storage' => fn (): TokenStorageInterface => $this->createTokenStorage(null),
        ]));

        $this->expectException(AccessDeniedException::class);

        $controller->addressSuggestions(Request::create('/data/address-suggestions'), $this->createStub(AddressRepository::class));
    }

    private function createTokenStorage(?object $user): TokenStorageInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $storage = $this->createStub(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn(null === $user ? null : $token);

        return $storage;
    }
}
