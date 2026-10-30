<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Controller\DataController;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\AccountRepository;
use AvalynxOffice\Repository\AddressRepository;
use AvalynxOffice\Service\DataService;
use AvalynxOffice\Service\Mail\UserAccountProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DataControllerTest extends TestCase
{
    public function testAllReturnsJsonResponseWithoutDoubleEncoding(): void
    {
        $account = (new Account())->setName('Personal')->setEmail('user@example.com');
        $user = new User();
        $user->addAccount($account);
        $dataService = $this->createMock(DataService::class);
        $dataService->expects(self::once())
            ->method('getAllData')
            ->with([$account])
            ->willReturn(['accounts' => [['id' => 1]]]);

        $controller = new DataController();
        $controller->setContainer(new ServiceLocator([
            'security.token_storage' => fn (): TokenStorageInterface => $this->createTokenStorage($user),
        ]));
        $accountProvider = new UserAccountProvider($this->createStub(AccountRepository::class));

        $response = $controller->all($dataService, $accountProvider);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"accounts":[{"id":1}]}', (string) $response->getContent());
    }

    public function testAddressSuggestionsFormatsNamedAndUnnamedAddressesScopedToUserAccounts(): void
    {
        $account = (new Account())->setName('Personal')->setEmail('user@example.com');
        $user = new User();
        $user->addAccount($account);

        $controller = new DataController();
        $controller->setContainer(new ServiceLocator([
            'security.token_storage' => fn (): TokenStorageInterface => $this->createTokenStorage($user),
        ]));

        $request = Request::create('/data/address-suggestions?search=sup');
        $repository = $this->createMock(AddressRepository::class);
        $repository->expects(self::once())
            ->method('findForAutocomplete')
            ->with('sup', [$account])
            ->willReturn([
                (new Address())->setEmail('named@example.com')->setName('Named User'),
                (new Address())->setEmail('plain@example.com'),
            ]);
        $accountProvider = new UserAccountProvider($this->createStub(AccountRepository::class));

        $response = $controller->addressSuggestions($request, $repository, $accountProvider);

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

        $controller->addressSuggestions(
            Request::create('/data/address-suggestions'),
            $this->createStub(AddressRepository::class),
            new UserAccountProvider($this->createStub(AccountRepository::class)),
        );
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
