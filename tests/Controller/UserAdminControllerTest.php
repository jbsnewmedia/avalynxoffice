<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
#[Group('functional')]
final class UserAdminControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
        $this->seedUsers();
    }

    public function testUsersPageRequiresLogin(): void
    {
        $this->client->request('GET', '/admin/users');

        self::assertResponseRedirects('/login');
    }

    public function testUsersPageRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUsersPageRendersDatatableContainer(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        self::assertStringContainsString('data-avalynxdatatable', $content);
        self::assertStringContainsString('data-csrf-token="', $content);
        self::assertStringContainsString($this->generateUrl('admin_users_data'), $content);
        self::assertStringContainsString($this->generateUrl('admin_users_new'), $content);
        // The user rows live behind the datatable JSON endpoint, not in the
        // page markup.
        self::assertStringNotContainsString('user@example.com', $content);
    }

    public function testDataRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/admin/users/data');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDataRejectsMissingOrInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/admin/users/data');
        self::assertResponseStatusCodeSame(403);
        $payload = $this->json();
        self::assertFalse($payload['success']);
        self::assertArrayNotHasKey('data', $payload);

        $this->client->request('POST', '/admin/users/data', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDataAcceptsCsrfTokenViaHeader(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('datatable');

        $this->client->request('POST', '/admin/users/data', [], [], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['count']['total']);
    }

    public function testDataReturnsAllUsersSortedByEmail(): void
    {
        $this->client->loginUser($this->admin);
        $data = $this->fetchData();

        self::assertSame(2, $data['count']['total']);
        self::assertSame(2, $data['count']['filtered']);
        self::assertSame(1, $data['count']['page']);
        self::assertSame(10, $data['count']['perpage']);
        self::assertSame(1, $data['count']['start']);
        self::assertSame(2, $data['count']['end']);
        self::assertSame(['email' => 'asc'], $data['sorting']);
        self::assertSame('', $data['search']['value']);
        self::assertCount(2, $data['data']);
        self::assertSame('admin@example.com', $data['data'][0]['data']['email']);
        self::assertSame('user@example.com', $data['data'][1]['data']['email']);
        self::assertSame('', $data['data'][0]['class']);
        self::assertSame('', $data['data'][1]['class']);
        // The admin row disables delete (last active admin and own account),
        // the regular user stays fully manageable.
        self::assertStringContainsString('disabled', $data['data'][0]['data']['options']);
        self::assertStringNotContainsString('disabled', $data['data'][1]['data']['options']);
    }

    public function testDataSearchFiltersByEmail(): void
    {
        $this->client->loginUser($this->admin);
        $data = $this->fetchData(['search' => 'user@']);

        self::assertSame(2, $data['count']['total']);
        self::assertSame(1, $data['count']['filtered']);
        self::assertCount(1, $data['data']);
        self::assertSame('user@example.com', $data['data'][0]['data']['email']);
    }

    public function testDataSearchWithoutMatches(): void
    {
        $this->client->loginUser($this->admin);
        $data = $this->fetchData(['search' => 'nobody@']);

        self::assertSame(2, $data['count']['total']);
        self::assertSame(0, $data['count']['filtered']);
        self::assertSame(0, $data['count']['start']);
        self::assertSame(0, $data['count']['end']);
        self::assertSame([], $data['data']);
    }

    public function testDataSortingByLastAuthDescending(): void
    {
        $this->client->loginUser($this->admin);
        $data = $this->fetchData(['sorting' => json_encode(['lastAuthAt' => 'desc'])]);

        // The response echoes the sorting keyed by column id, because the
        // datatable adopts it into its own client-side state.
        self::assertSame(['lastAuthAt' => 'desc'], $data['sorting']);
        self::assertSame('admin@example.com', $data['data'][0]['data']['email']);
    }

    public function testDataSortingUnknownColumnsFallBackToEmail(): void
    {
        $this->client->loginUser($this->admin);

        $invalidJson = $this->fetchData(['sorting' => '{not-json']);
        self::assertSame(['email' => 'asc'], $invalidJson['sorting']);

        $unknownColumn = $this->fetchData(['sorting' => json_encode(['hacker' => 'desc'])]);
        self::assertSame(['email' => 'asc'], $unknownColumn['sorting']);

        $rows = $unknownColumn['data'];
        self::assertSame('admin@example.com', $rows[0]['data']['email']);
    }

    public function testDataPaginationAndPerPageFallback(): void
    {
        $this->client->loginUser($this->admin);

        $perPage = $this->fetchData(['perpage' => '25']);
        self::assertSame(25, $perPage['count']['perpage']);

        $invalidPerPage = $this->fetchData(['perpage' => '13']);
        self::assertSame(10, $invalidPerPage['count']['perpage']);

        $secondPage = $this->fetchData(['page' => '2']);
        self::assertSame(2, $secondPage['count']['page']);
        self::assertSame([], $secondPage['data']);
    }

    public function testDataRowContainsRolesStateAndActions(): void
    {
        $this->client->loginUser($this->admin);
        $data = $this->fetchData(['search' => 'user@']);

        $row = $data['data'][0]['data'];
        self::assertArrayHasKey('id', $row);
        self::assertStringContainsString('>User<', $row['roles']);
        self::assertStringContainsString('fa-check', $row['active']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $row['lastAuthAt']);
        self::assertStringContainsString($this->generateUrl('admin_users_edit', ['id' => $row['id']]), $row['options']);
        self::assertStringContainsString($this->generateUrl('admin_users_delete', ['id' => $row['id']]), $row['options']);
        self::assertStringContainsString('confirm-action', $row['options']);
        // Editable regular user: delete is not disabled.
        self::assertStringNotContainsString('disabled', $row['options']);

        // Deactivated users are rendered with the muted row class.
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        \assert($reloaded instanceof User);
        $reloaded->setActive(false);
        $this->em->flush();
        $data = $this->fetchData(['search' => 'user@']);
        self::assertSame('table-secondary', $data['data'][0]['class']);

        // Users without authentication have no last authentication date.
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        \assert($reloaded instanceof User);
        $reloaded->setLastAuthAt(null);
        $this->em->flush();
        $data = $this->fetchData(['search' => 'user@']);
        self::assertStringContainsString('text-body-secondary', $data['data'][0]['data']['lastAuthAt']);
    }

    public function testNewModalRequiresXmlHttpRequest(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewModalRendersForm(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/new', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="admin-user-form"', $content);
        self::assertStringContainsString('data-avalynxform', $content);
        self::assertStringContainsString($this->generateUrl('admin_users_new'), $content);
        self::assertStringContainsString('name="user_admin[email]"', $content);
        self::assertStringContainsString('name="user_admin[password]"', $content);
        self::assertStringContainsString('name="user_admin[active]"', $content);
        self::assertStringContainsString('name="user_admin[admin]"', $content);
        self::assertStringContainsString('name="user_admin[_token]"', $content);
        self::assertStringContainsString('invalid-feedback', $content);
    }

    public function testCreateRequiresXmlHttpRequest(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/new', [
            'user_admin' => ['email' => 'new@example.com', 'password' => 'secret', '_token' => $this->setCsrfToken('admin_user')],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']));
    }

    public function testCreateWithInvalidEmailReturnsFieldErrors(): void
    {
        $this->client->loginUser($this->admin);
        $this->createUserForm(['email' => 'not-an-email', 'password' => 'secret']);

        self::assertResponseStatusCodeSame(422);

        $payload = $this->json();
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('user_admin[email]', $payload['invalid']);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'not-an-email']));
    }

    public function testCreateWithEmptyPasswordReturnsFieldError(): void
    {
        $this->client->loginUser($this->admin);
        $this->createUserForm(['email' => 'new@example.com', 'password' => '']);

        self::assertResponseStatusCodeSame(422);

        $payload = $this->json();
        self::assertArrayHasKey('user_admin[password]', $payload['invalid']);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']));
    }

    public function testCreateWithExistingEmailReturnsFieldError(): void
    {
        $this->client->loginUser($this->admin);
        $this->createUserForm(['email' => 'USER@example.com', 'password' => 'secret']);

        self::assertResponseStatusCodeSame(422);

        $payload = $this->json();
        self::assertArrayHasKey('user_admin[email]', $payload['invalid']);
        self::assertCount(2, $this->em->getRepository(User::class)->findAll());
    }

    public function testCreateStoresUserWithHashedPassword(): void
    {
        $this->client->loginUser($this->admin);
        $this->createUserForm(['email' => 'new@example.com', 'password' => 'secret', 'active' => '1', 'admin' => '1']);

        self::assertResponseIsSuccessful();

        $payload = $this->json();
        self::assertTrue($payload['success']);
        self::assertSame('/admin/users', $payload['redirect']);
        self::assertSame('User was created.', $payload['message']);

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertInstanceOf(User::class, $created);
        self::assertTrue($created->isActive());
        self::assertContains('ROLE_ADMIN', $created->getRoles());
        self::assertNotSame('secret', $created->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($created, 'secret'));
    }

    public function testCreateDefaultsToInactiveRegularUser(): void
    {
        $this->client->loginUser($this->admin);
        $this->createUserForm(['email' => 'regular@example.com', 'password' => 'secret']);

        self::assertResponseIsSuccessful();

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'regular@example.com']);
        self::assertInstanceOf(User::class, $created);
        self::assertFalse($created->isActive());
        self::assertNotContains('ROLE_ADMIN', $created->getRoles());
    }

    public function testEditModalRequiresXmlHttpRequest(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/'.$this->user->getId()->toRfc4122().'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditModalForUnknownUserReturnsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/00000000-0000-7000-8000-000000000000/edit', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testEditModalRendersPrefilledForm(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/'.$this->user->getId()->toRfc4122().'/edit', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('value="user@example.com"', $content);
        self::assertStringContainsString('checked', $content);
        self::assertStringContainsString('Leave empty to keep the current password.', $content);
    }

    public function testUpdateUnknownUserReturnsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/00000000-0000-7000-8000-000000000000/edit', [
            'user_admin' => ['_token' => $this->setCsrfToken('admin_user')],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateRequiresXmlHttpRequest(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$this->user->getId()->toRfc4122().'/edit', [
            'user_admin' => ['_token' => $this->setCsrfToken('admin_user')],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateWithInvalidEmailReturnsFieldError(): void
    {
        $this->client->loginUser($this->admin);
        $this->updateUser($this->user, ['email' => 'still-not-an-email']);

        self::assertResponseStatusCodeSame(422);

        $payload = $this->json();
        self::assertArrayHasKey('user_admin[email]', $payload['invalid']);
    }

    public function testUpdateWithOtherUsersEmailReturnsFieldError(): void
    {
        $this->client->loginUser($this->admin);
        $this->updateUser($this->user, ['email' => 'admin@example.com']);

        self::assertResponseStatusCodeSame(422);

        $payload = $this->json();
        self::assertArrayHasKey('user_admin[email]', $payload['invalid']);
    }

    public function testUpdateChangesEmailPasswordAndDeactivates(): void
    {
        $this->client->loginUser($this->admin);
        $oldHash = $this->user->getPassword();
        $this->updateUser($this->user, [
            'email' => 'renamed@example.com',
            'password' => 'new-secret',
        ]);

        self::assertResponseIsSuccessful();

        $payload = $this->json();
        self::assertTrue($payload['success']);
        self::assertSame('/admin/users', $payload['redirect']);
        self::assertSame('User was saved.', $payload['message']);

        $this->em->clear();
        $updated = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $updated);
        self::assertSame('renamed@example.com', $updated->getEmail());
        self::assertFalse($updated->isActive());
        self::assertNotSame($oldHash, $updated->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($updated, 'new-secret'));
    }

    public function testUpdateKeepsPasswordWhenEmpty(): void
    {
        $this->client->loginUser($this->admin);
        $oldHash = $this->user->getPassword();
        $this->updateUser($this->user, ['active' => '1']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $updated = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $updated);
        self::assertSame($oldHash, $updated->getPassword());
        self::assertTrue($updated->isActive());
    }

    public function testUpdateActivatesInactiveUser(): void
    {
        $this->user->setActive(false);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->updateUser($this->user, ['active' => '1']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $updated = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $updated);
        self::assertTrue($updated->isActive());
    }

    public function testUpdateGrantsAndRevokesAdminRole(): void
    {
        $this->client->loginUser($this->admin);
        $this->updateUser($this->user, ['admin' => '1', 'active' => '1']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $granted = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $granted);
        self::assertContains('ROLE_ADMIN', $granted->getRoles());

        $this->updateUser($this->user, ['active' => '1']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $revoked = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $revoked);
        self::assertNotContains('ROLE_ADMIN', $revoked->getRoles());
    }

    public function testUpdateRejectsDeactivatingLastActiveAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $this->updateUser($this->admin, []);

        self::assertResponseStatusCodeSame(422);

        $payload = $this->json();
        self::assertArrayHasKey('user_admin[active]', $payload['invalid']);

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->admin->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertTrue($refreshed->isActive());
    }

    public function testUpdateRejectsRevokingLastAdminRole(): void
    {
        $this->client->loginUser($this->admin);
        $this->updateUser($this->admin, ['active' => '1']);

        self::assertResponseStatusCodeSame(422);

        $payload = $this->json();
        self::assertArrayHasKey('user_admin[admin]', $payload['invalid']);

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->admin->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertContains('ROLE_ADMIN', $refreshed->getRoles());
    }

    public function testDeleteRequiresValidToken(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$this->user->getId()->toRfc4122().'/delete');

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->find(User::class, $this->user->getId()));
    }

    public function testDeleteUnknownUserReturnsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/00000000-0000-7000-8000-000000000000/delete', [
            '_token' => $this->setCsrfToken('admin_users_delete_00000000-0000-7000-8000-000000000000'),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRejectsOwnAccount(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$this->admin->getId()->toRfc4122().'/delete', [
            '_token' => $this->setCsrfToken('admin_users_delete_'.$this->admin->getId()->toRfc4122()),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->find(User::class, $this->admin->getId()));
    }

    public function testDeleteRejectsLastActiveAdmin(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $secondAdmin = new User();
        $secondAdmin->setEmail('admin2@example.com');
        $secondAdmin->setRoles(['ROLE_ADMIN']);
        $secondAdmin->setPassword($hasher->hashPassword($secondAdmin, 'secret'));
        $secondAdmin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($secondAdmin);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$secondAdmin->getId()->toRfc4122().'/delete', [
            '_token' => $this->setCsrfToken('admin_users_delete_'.$secondAdmin->getId()->toRfc4122()),
        ]);

        // The second admin is deletable while another active administrator
        // remains, but the last remaining active administrator is not.
        self::assertResponseRedirects('/admin/users');

        $this->client->request('POST', '/admin/users/'.$this->admin->getId()->toRfc4122().'/delete', [
            '_token' => $this->setCsrfToken('admin_users_delete_'.$this->admin->getId()->toRfc4122()),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->find(User::class, $this->admin->getId()));
    }

    public function testDeleteRejectsDeletingTheLastRemainingActiveAdmin(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $secondAdmin = new User();
        $secondAdmin->setEmail('admin2@example.com');
        $secondAdmin->setRoles(['ROLE_ADMIN']);
        $secondAdmin->setPassword($hasher->hashPassword($secondAdmin, 'secret'));
        $secondAdmin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($secondAdmin);
        $this->em->flush();

        // The logged-in administrator was deactivated after login (the test
        // login keeps the session), so the second administrator is the last
        // remaining ACTIVE administrator.
        $this->admin->setActive(false);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$secondAdmin->getId()->toRfc4122().'/delete', [
            '_token' => $this->setCsrfToken('admin_users_delete_'.$secondAdmin->getId()->toRfc4122()),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->find(User::class, $secondAdmin->getId()));
    }

    public function testDeleteRemovesUser(): void
    {
        $userId = $this->user->getId();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$userId->toRfc4122().'/delete', [
            '_token' => $this->setCsrfToken('admin_users_delete_'.$userId->toRfc4122()),
        ]);

        self::assertResponseRedirects('/admin/users');

        $flashBag = $this->client->getRequest()->getSession()->getFlashBag();
        self::assertSame(['admin.users.deleted'], $flashBag->get('success'));

        self::assertNull($this->em->find(User::class, $userId));
    }

    /**
     * @param array<string, string> $fields
     */
    private function createUserForm(array $fields): void
    {
        $this->client->request('POST', '/admin/users/new', [
            'user_admin' => [...$fields, '_token' => $this->setCsrfToken('admin_user')],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * Submits the admin user edit form for the given user. The email field
     * defaults to the current address so that partial submissions (e.g. only
     * changing the active flag) stay valid.
     *
     * @param array<string, string> $fields
     */
    private function updateUser(User $target, array $fields): void
    {
        $this->client->request('POST', '/admin/users/'.$target->getId()->toRfc4122().'/edit', [
            'user_admin' => [...['email' => $target->getEmail()], ...$fields, '_token' => $this->setCsrfToken('admin_user')],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function fetchData(array $params = []): array
    {
        $params += ['_token' => $this->setCsrfToken('datatable')];
        $this->client->request('POST', '/admin/users/data', $params);

        self::assertResponseIsSuccessful();

        $payload = $this->json();
        \assert(is_array($payload));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(is_array($decoded));

        return $decoded;
    }

    /**
     * Stores a CSRF token directly in the client session and returns it.
     * The value is then accepted for the given token id.
     */
    private function setCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/login');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }

    private function generateUrl(string $route, array $parameters = []): string
    {
        /** @var \Symfony\Component\Routing\RouterInterface $router */
        $router = static::getContainer()->get('router');

        return $router->generate($route, $parameters);
    }

    private function seedUsers(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->admin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->user = new User();
        $this->user->setEmail('user@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'user'));
        $this->user->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->em->persist($this->admin);
        $this->em->persist($this->user);
        $this->em->flush();
    }
}
