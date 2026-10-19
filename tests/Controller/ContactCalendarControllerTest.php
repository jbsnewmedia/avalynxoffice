<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class ContactCalendarControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $adminUser;
    private Mailbox $mailbox;
    private Mailbox $secondMailbox;
    private Address $jane;
    private Address $john;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->refreshDatabase($this->entityManager);
        $this->seedData();
    }

    public function testContactIndexShowsThreePaneLayoutWithFirstContact(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('jane@example.com', $content);
        self::assertStringContainsString('Example GmbH', $content);
        self::assertStringContainsString('+49 170 111', $content);
        self::assertStringContainsString('John Roe', $content);

        // Editable: plugin-driven action bar with edit and delete for the active contact.
        self::assertSelectorExists('#contact-detail .action-btn-modal');
        self::assertSelectorExists('#contact-detail .confirm-action');
        self::assertSelectorExists('#contact-detail [data-action-id="contact-edit"]');
        self::assertSelectorExists('#contact-detail [data-action-id="contact-delete"]');
        self::assertSelectorExists('#contact-detail [data-overflow-group="actions"]');

        // Three-pane layout: groups, list with search, readonly detail.
        self::assertSelectorExists('aside .list-group');
        self::assertSelectorExists('#contact-detail .card');
        self::assertSelectorExists('form[role="search"][action="/contacts/all"]');

        // Module switcher marks the active module and links the others.
        self::assertSelectorExists('a[href="/contacts"][aria-current="page"]');
        self::assertSelectorExists('a[href="/calendar"]');
        self::assertSelectorExists('a[href="/mails"]');
        self::assertSelectorNotExists('a[href="/mails"][aria-current="page"]');
    }

    public function testContactGroupFiltersListAndShowsCounts(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/contacts/D');
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Jane Doe', $content);
        self::assertStringNotContainsString('John Roe', $content);
        self::assertSelectorExists('a[href="/contacts/D"][aria-current="page"]');

        $this->client->request('GET', '/contacts/R');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/contacts/R"][aria-current="page"]');
    }

    public function testContactDetailRouteSelectsGivenContact(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', sprintf('/contacts/D/%s', $this->jane->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#contact-detail .card');
        self::assertStringContainsString('jane@example.com', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownGroupOrContactFallsBackOrFails(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/contacts/Z');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', sprintf('/contacts/R/%s', $this->jane->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('John Roe', (string) $this->client->getResponse()->getContent());
    }

    public function testContactSearchFiltersAcrossFields(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/contacts', ['q' => 'Example GmbH']);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Jane Doe', $content);
        self::assertStringNotContainsString('John Roe', $content);

        $this->client->request('GET', '/contacts', ['q' => 'does-not-match-anything']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.email-list-container');
        self::assertStringContainsString('No contacts matched your search.', (string) $this->client->getResponse()->getContent());
    }

    public function testCalendarIndexShowsThreePaneLayout(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();

        self::assertSelectorExists('a[href="/calendar"][aria-current="page"]');
        self::assertSelectorExists('a[href="/contacts"]');
        self::assertSelectorExists('#event-detail');
        self::assertSelectorExists('form[role="search"][action^="/calendar"]');
    }

    public function testMailIndexLinksAllModules(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mails"][aria-current="page"]', 'mail module active');
        self::assertSelectorExists('a[href="/contacts"]');
        self::assertSelectorExists('a[href="/calendar"]');
    }

    public function testLimitedUserSeesOnlyContactsOfOwnMailboxes(): void
    {
        $user = (new User())
            ->setEmail('limited@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_USER']);
        $ownMailbox = (new Mailbox())
            ->setName('Private')
            ->setEmail('private@example.com');
        $user->addMailbox($ownMailbox);

        $ownContact = (new Address())
            ->setEmail('own@example.com')
            ->setFirstname('Own')
            ->setLastname('Contact');
        $ownContact->setIsContact(true);
        $ownMailbox->addAddress($ownContact);

        $this->entityManager->persist($user);
        $this->entityManager->persist($ownMailbox);
        $this->entityManager->persist($ownContact);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('own@example.com', $content);
        self::assertStringNotContainsString('jane@example.com', $content);
    }

    public function testUserWithoutMailboxesSeesEmptyState(): void
    {
        $user = (new User())
            ->setEmail('lonely@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_USER']);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Select a contact from the list.', (string) $this->client->getResponse()->getContent());
    }

    public function testMailboxScopeFiltersContacts(): void
    {
        $this->client->loginUser($this->adminUser);

        $secondMailboxId = (string) $this->secondMailbox->getId();

        $this->client->request('GET', '/contacts', ['mailbox' => $secondMailboxId]);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('scoped@example.com', $content);
        self::assertStringNotContainsString('jane@example.com', $content);

        $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('scoped@example.com', $content);
        self::assertStringContainsString('jane@example.com', $content);
    }

    public function testContactListTagsItemsWithMailboxWhenAllAccountsSelected(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();

        self::assertSelectorExists('.email-list-container .mailbox-tag');
        self::assertSelectorTextContains('.email-list-container .mailbox-tag', 'Support');
    }

    public function testContactListHidesMailboxTagWhenMailboxIsScoped(): void
    {
        $this->client->loginUser($this->adminUser);

        $secondMailboxId = (string) $this->secondMailbox->getId();
        $this->client->request('GET', '/contacts', ['mailbox' => $secondMailboxId]);
        self::assertResponseIsSuccessful();

        self::assertSelectorNotExists('.email-list-container .mailbox-tag');
    }

    public function testCalendarListTagsItemsWithMailboxWhenAllAccountsSelected(): void
    {
        $event = new \AvalynxOffice\Entity\CalendarEvent();
        $event->setTitle('Tagged Event');
        $event->setStartAt(new \DateTimeImmutable('now'));
        $event->setMailbox($this->mailbox);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();

        self::assertSelectorExists('.email-list-container .mailbox-tag');
        self::assertSelectorTextContains('.email-list-container .mailbox-tag', 'Support');
    }

    public function testForeignMailboxScopeIsDenied(): void
    {
        $foreignMailbox = (new Mailbox())
            ->setName('Foreign')
            ->setEmail('foreign@example.com');
        $this->entityManager->persist($foreignMailbox);
        $this->entityManager->flush();
        $foreignMailboxId = (string) $foreignMailbox->getId();

        $user = (new User())
            ->setEmail('restricted@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_USER']);
        $ownMailbox = (new Mailbox())
            ->setName('Own')
            ->setEmail('own-box@example.com');
        $user->addMailbox($ownMailbox);
        $this->entityManager->persist($user);
        $this->entityManager->persist($ownMailbox);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request('GET', '/contacts', ['mailbox' => $foreignMailboxId]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testContactEditModalRequiresXhrAndShowsData(): void
    {
        $this->client->loginUser($this->adminUser);

        $janeId = (string) $this->jane->getId();

        $this->client->request('GET', sprintf('/contacts/all/%s/modal', $janeId));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/contacts/all/%s/modal', $janeId), [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('jane@example.com', $content);
        self::assertStringContainsString('Example GmbH', $content);
    }

    public function testContactEditModalForUnknownOrForeignContactFails(): void
    {
        $user = (new User())
            ->setEmail('stranger@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_USER']);
        $foreignMailbox = (new Mailbox())
            ->setName('Foreign')
            ->setEmail('foreign2@example.com');
        $foreignContact = (new Address())
            ->setEmail('secret-person@example.com');
        $foreignContact->setIsContact(true);
        $foreignMailbox->addAddress($foreignContact);
        $user->addMailbox($this->mailbox);
        $this->entityManager->persist($user);
        $this->entityManager->persist($foreignMailbox);
        $this->entityManager->persist($foreignContact);
        $this->entityManager->flush();

        $foreignId = (string) $foreignContact->getId();

        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/contacts/all/%s/modal', $foreignId), [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/contacts/all/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/modal', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testContactUpdatePersistsChanges(): void
    {
        $this->client->loginUser($this->adminUser);

        $janeId = (string) $this->jane->getId();
        $token = $this->modalCsrfToken($janeId);

        $this->client->request('POST', sprintf('/contacts/all/%s/modal', $janeId), [
            'contact_form' => [
                '_token' => $token,
                'firstname' => 'Janet',
                'lastname' => 'Doe-Second',
                'name' => 'Janet Doe-Second',
                'email' => 'janet@example.com',
                'company' => 'Example Holdings',
                'businessPhone' => '+49 6131 999',
                'mobilePhone' => '',
                'homePhone' => '',
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
                $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(Address::class)->find($this->jane->getId());
        self::assertInstanceOf(Address::class, $updated);
        self::assertSame('Janet', $updated->getFirstname());
        self::assertSame('janet@example.com', $updated->getEmail());
        self::assertSame('Example Holdings', $updated->getCompany());
        self::assertSame('+49 6131 999', $updated->getBusinessPhone());
        self::assertNull($updated->getMobilePhone());
    }

    public function testContactUpdateWithInvalidEmailReturnsForm(): void
    {
        $this->client->loginUser($this->adminUser);

        $janeId = (string) $this->jane->getId();

        $this->client->request('POST', sprintf('/contacts/all/%s/modal', $janeId), [
            'contact_form' => [
                'firstname' => 'Jane',
                'email' => 'not-an-email',
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('invalid', $data);
    }

    public function testContactCreateAddsContactToSelectedMailbox(): void
    {
        $this->client->loginUser($this->adminUser);

        $secondMailboxId = (string) $this->secondMailbox->getId();

        $crawler = $this->client->request('GET', '/contacts/new', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="contact_form[_token]"]')->attr('value');

        $this->client->request('POST', '/contacts/new', [
            'contact_form' => [
                '_token' => $token,
                'firstname' => 'Nina',
                'lastname' => 'Neu',
                'email' => 'nina@example.com',
                'mailbox' => $secondMailboxId,
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
                $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $created = $this->entityManager->getRepository(Address::class)->findOneBy(['email' => 'nina@example.com']);
        self::assertInstanceOf(Address::class, $created);
        self::assertTrue($created->isContact());
        $refetchedMailbox = $this->entityManager->getRepository(Mailbox::class)->find($this->secondMailbox->getId());
        self::assertInstanceOf(Mailbox::class, $refetchedMailbox);
        $emails = array_map(static fn (Address $a): string => $a->getEmail(), $refetchedMailbox->getAddresses()->toArray());
        self::assertContains('nina@example.com', $emails);
    }

    public function testContactCreateWithoutEmailFailsValidation(): void
    {
        $this->client->loginUser($this->adminUser);

        $crawler = $this->client->request('GET', '/contacts/new', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="contact_form[_token]"]')->attr('value');

        $this->client->request('POST', '/contacts/new', [
            'contact_form' => [
                '_token' => $token,
                'firstname' => 'Ohne',
                'email' => '',
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testContactCreateWithForeignMailboxIsDenied(): void
    {
        $foreignMailbox = (new Mailbox())
            ->setName('Foreign')
            ->setEmail('foreign3@example.com');
        $this->entityManager->persist($foreignMailbox);
        $this->entityManager->flush();
        $foreignMailboxId = (string) $foreignMailbox->getId();

        $user = (new User())
            ->setEmail('restricted@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_USER']);
        $ownMailbox = (new Mailbox())
            ->setName('Own')
            ->setEmail('own-box@example.com');
        $user->addMailbox($ownMailbox);
        $this->entityManager->persist($user);
        $this->entityManager->persist($ownMailbox);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/contacts/new', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="contact_form[_token]"]')->attr('value');

        $this->client->request('POST', '/contacts/new', [
            'contact_form' => [
                '_token' => $token,
                'firstname' => 'X',
                'email' => 'x@example.com',
                'mailbox' => $foreignMailboxId,
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager->getRepository(Address::class)->findOneBy(['email' => 'x@example.com']));
    }

    public function testContactDeleteRemovesContactWithValidToken(): void
    {
        $this->client->loginUser($this->adminUser);

        $janeId = (string) $this->jane->getId();

        $crawler = $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter(sprintf('a[data-action-id="contact-delete"][href*="%s"]', $janeId))->attr('data-token');
        self::assertNotEmpty($token);

        $this->client->request('POST', sprintf('/contacts/all/%s/delete', $janeId), [
            '_token' => $token,
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"success":true}', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Address::class)->find($this->jane->getId()));
    }

    public function testContactDeleteViaPlainPostRedirectsAndRemovesContact(): void
    {
        $this->client->loginUser($this->adminUser);

        $janeId = (string) $this->jane->getId();

        $crawler = $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter(sprintf('a[data-action-id="contact-delete"][href*="%s"]', $janeId))->attr('data-token');

        $this->client->request('POST', sprintf('/contacts/all/%s/delete', $janeId), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/contacts/all');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Address::class)->find($this->jane->getId()));
    }

    public function testContactDeleteWithInvalidTokenIsRejected(): void
    {
        $this->client->loginUser($this->adminUser);

        $janeId = (string) $this->jane->getId();

        $this->client->request('POST', sprintf('/contacts/all/%s/delete', $janeId), [
            '_token' => 'invalid-token',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(400);
        self::assertNotNull($this->entityManager->getRepository(Address::class)->find($this->jane->getId()));
    }

    public function testContactDeleteWithInvalidPlainPostIsForbidden(): void
    {
        $this->client->loginUser($this->adminUser);

        $janeId = (string) $this->jane->getId();

        $this->client->request('POST', sprintf('/contacts/all/%s/delete', $janeId), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager->getRepository(Address::class)->find($this->jane->getId()));
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/contacts');
        self::assertResponseRedirects();

        $this->client->request('GET', '/calendar');
        self::assertResponseRedirects();
    }

    /**
     * Renders the edit modal for the given contact and returns the form's
     * CSRF token, emulating the browser flow.
     */
    private function modalCsrfToken(string $contactId): string
    {
        $crawler = $this->client->request('GET', sprintf('/contacts/all/%s/modal', $contactId), [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="contact_form[_token]"]')->attr('value');
        self::assertNotEmpty($token);

        return (string) $token;
    }

    private function seedData(): void
    {        $this->adminUser = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN']);

        $this->mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');

        $this->jane = (new Address())
            ->setEmail('jane@example.com')
            ->setFirstname('Jane')
            ->setLastname('Doe')
            ->setCompany('Example GmbH')
            ->setMobilePhone('+49 170 111')
            ->setBusinessPhone('+49 6131 222');
        $this->jane->setIsContact(true);

        $this->john = (new Address())
            ->setEmail('john@example.com')
            ->setFirstname('John')
            ->setLastname('Roe')
            ->setHomePhone('+49 6131 333');
        $this->john->setIsContact(true);

        $plain = (new Address())
            ->setEmail('plain@example.com');

        $this->secondMailbox = (new Mailbox())
            ->setName('Vertrieb')
            ->setEmail('vertrieb@example.com');
        $scopedContact = (new Address())
            ->setEmail('scoped@example.com')
            ->setFirstname('Scoped');
        $scopedContact->setIsContact(true);
        $this->secondMailbox->addAddress($scopedContact);

        $this->mailbox->addAddress($this->jane);
        $this->mailbox->addAddress($this->john);
        $this->mailbox->addAddress($plain);

        $this->adminUser->addMailbox($this->mailbox);
        $this->adminUser->addMailbox($this->secondMailbox);

        $this->entityManager->persist($this->adminUser);
        $this->entityManager->persist($this->mailbox);
        $this->entityManager->persist($this->secondMailbox);
        $this->entityManager->persist($this->jane);
        $this->entityManager->persist($this->john);
        $this->entityManager->persist($plain);
        $this->entityManager->persist($scopedContact);
        $this->entityManager->flush();
    }
}
