<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAddress;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Address suggestions must only expose addresses that are linked to one of
 * the accounts the current user may access.
 */
#[Group('integration')]
final class DataControllerFunctionalTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private User $admin;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->entityManager);

        $ownAccount = (new Account())->setName('Own')->setEmail('own@example.com');
        $foreignAccount = (new Account())->setName('Foreign')->setEmail('foreign@example.com');

        $this->user = (new User())->setEmail('user@example.com')->setPassword('secret');
        $this->user->addAccount($ownAccount);
        $this->admin = (new User())->setEmail('admin@example.com')->setPassword('secret')->setRoles(['ROLE_ADMIN']);

        $ownAddress = (new Address())->setEmail('own-contact@example.com')->setName('Own Contact');
        $foreignAddress = (new Address())->setEmail('foreign-contact@example.com')->setName('Foreign Contact');
        $unlinkedAddress = (new Address())->setEmail('unlinked-contact@example.com');

        foreach ([$ownAccount, $foreignAccount, $this->user, $this->admin, $ownAddress, $foreignAddress, $unlinkedAddress] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist((new AccountAddress())->setAccount($ownAccount)->setAddress($ownAddress));
        $this->entityManager->persist((new AccountAddress())->setAccount($foreignAccount)->setAddress($foreignAddress));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testAddressSuggestionsAreLimitedToTheUsersAccounts(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/data/address-suggestions?search=contact');

        self::assertResponseIsSuccessful();
        $keys = array_column($this->json(), 'key');

        self::assertSame(['own-contact@example.com'], $keys);
    }

    public function testAdminsSeeAddressesOfAllAccounts(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/data/address-suggestions?search=contact');

        self::assertResponseIsSuccessful();
        $keys = array_column($this->json(), 'key');

        self::assertSame(['foreign-contact@example.com', 'own-contact@example.com'], $keys);
    }

    public function testUserWithoutAccountsGetsNoSuggestions(): void
    {
        $lonely = (new User())->setEmail('lonely@example.com')->setPassword('secret');
        $this->entityManager->persist($lonely);
        $this->entityManager->flush();

        $this->client->loginUser($lonely);
        $this->client->request('GET', '/data/address-suggestions?search=contact');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json());
    }

    public function testAddressSuggestionsRequireLogin(): void
    {
        $this->client->request('GET', '/data/address-suggestions?search=contact');

        self::assertResponseRedirects('/login');
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        \assert(is_array($decoded));

        return $decoded;
    }
}
