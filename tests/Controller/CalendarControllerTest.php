<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class CalendarControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $adminUser;
    private Mailbox $mailbox;
    private Mailbox $salesMailbox;
    private CalendarEvent $kickoff;
    private CalendarEvent $review;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->refreshDatabase($this->entityManager);
        $this->seedData();
    }

    public function testCalendarIndexShowsThreePaneLayoutWithEvents(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertStringContainsString('Quartals-Review', $content);
        self::assertStringContainsString('Raum Mainz', $content);

        // Editable: action bar with icon-only edit and delete for the active event.
        self::assertSelectorExists('#event-detail .action-btn-modal');
        self::assertSelectorExists('#event-detail .confirm-action');
        // The managed badge was replaced by the view switcher dropdown in the action bar.
        self::assertSelectorNotExists('#event-detail .badge');
        self::assertSelectorExists('#event-detail .calendar-view-switcher-dropdown .dropdown-toggle');
        self::assertSelectorExists('#event-detail .calendar-view-switcher-dropdown .dropdown-item.active');
        self::assertSelectorExists('#event-detail .calendar-view-switcher-dropdown a[href*="view=day"]');
        self::assertSelectorExists('#event-detail .calendar-view-switcher-dropdown a[href*="view=week"]');
        self::assertSelectorExists('#event-detail .calendar-view-switcher-dropdown a[href*="view=month"]');
        // The origin account of the event is shown in the mailbox tag (like mails and contacts).
        self::assertSelectorExists('#event-detail .mailbox-tag');
        self::assertSelectorTextContains('#event-detail .mailbox-tag', 'Support');

        // Three-pane layout: period groups, list with search, readonly detail.
        self::assertSelectorExists('aside .list-group');
        self::assertSelectorExists('#event-detail .card');
        self::assertSelectorExists('form[role="search"][action="/calendar/all"]');
        self::assertSelectorExists('a[href="/calendar"][aria-current="page"]');
    }

    public function testCalendarMonthFilterShowsOnlyMatchingEvents(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffMonth = $this->kickoff->getStartAt()->format('Y-m');

        $this->client->request('GET', sprintf('/calendar/%s', $kickoffMonth));
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertStringNotContainsString('Quartals-Review', $content);
        self::assertSelectorExists(sprintf('a[href="/calendar/%s"][aria-current="page"]', $kickoffMonth));
    }

    public function testCalendarDetailRouteSelectsGivenEvent(): void
    {
        $this->client->loginUser($this->adminUser);

        $monthKey = $this->kickoff->getStartAt()->format('Y-m');

        $this->client->request('GET', sprintf('/calendar/%s/%s', $monthKey, $this->kickoff->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Vorstellung des Projektplans', (string) $this->client->getResponse()->getContent());
    }

    public function testCalendarAllDayEventRendersWithoutTime(): void
    {
        $this->client->loginUser($this->adminUser);

        $monthKey = $this->review->getStartAt()->format('Y-m');

        $this->client->request('GET', sprintf('/calendar/%s/%s', $monthKey, $this->review->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('All-day', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownMonthFailsWithNotFound(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar/1999-01');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarSearchFiltersEvents(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar', ['q' => 'Raum Mainz']);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertStringNotContainsString('Quartals-Review', $content);

        $this->client->request('GET', '/calendar', ['q' => 'nothing-matches-this']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No events matched your search.', (string) $this->client->getResponse()->getContent());
    }

    public function testLimitedUserSeesOnlyEventsOfOwnMailboxes(): void
    {
        $user = (new User())
            ->setEmail('limited@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_USER']);
        $ownMailbox = (new Mailbox())
            ->setName('Private')
            ->setEmail('private@example.com');
        $user->addMailbox($ownMailbox);

        $ownEvent = (new CalendarEvent())
            ->setTitle('Privater Termin')
            ->setStartAt(new \DateTimeImmutable('2026-03-01 10:00:00'));
        $ownEvent->setMailbox($ownMailbox);

        $this->entityManager->persist($user);
        $this->entityManager->persist($ownMailbox);
        $this->entityManager->persist($ownEvent);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Privater Termin', $content);
        self::assertStringNotContainsString('Projekt-Kickoff', $content);
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

        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Select an event from the list.', (string) $this->client->getResponse()->getContent());
    }

    public function testMailboxScopeFiltersEvents(): void
    {
        $this->client->loginUser($this->adminUser);

        $salesMailboxId = (string) $this->salesMailbox->getId();

        $this->client->request('GET', '/calendar', ['mailbox' => $salesMailboxId]);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sales-Treffen', $content);
        self::assertStringNotContainsString('Projekt-Kickoff', $content);

        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sales-Treffen', $content);
        self::assertStringContainsString('Projekt-Kickoff', $content);
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

        $this->client->request('GET', '/calendar', ['mailbox' => $foreignMailboxId]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEventEditModalRequiresXhrAndShowsData(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();
        $monthKey = $this->kickoff->getStartAt()->format('Y-m');

        $this->client->request('GET', sprintf('/calendar/%s/%s/modal', $monthKey, $kickoffId));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/calendar/%s/%s/modal', $monthKey, $kickoffId), [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertStringContainsString('Raum Mainz', $content);
    }

    public function testEventEditModalForUnknownOrForeignEventFails(): void
    {
        $user = (new User())
            ->setEmail('stranger@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_USER']);
        $foreignMailbox = (new Mailbox())
            ->setName('Foreign')
            ->setEmail('foreign-cal@example.com');
        $foreignEvent = (new CalendarEvent())
            ->setTitle('Geheimer Termin')
            ->setStartAt(new \DateTimeImmutable('2026-03-05 09:00:00'));
        $foreignEvent->setMailbox($foreignMailbox);
        $user->addMailbox($this->mailbox);
        $this->entityManager->persist($user);
        $this->entityManager->persist($foreignMailbox);
        $this->entityManager->persist($foreignEvent);
        $this->entityManager->flush();

        $foreignId = (string) $foreignEvent->getId();

        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/calendar/2026-03/%s/modal', $foreignId), [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/calendar/2026-03/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/modal', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testEventUpdatePersistsChanges(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();
        $monthKey = $this->kickoff->getStartAt()->format('Y-m');
        $token = $this->modalCsrfToken($kickoffId, $monthKey);

        $this->client->request('POST', sprintf('/calendar/%s/%s/modal', $monthKey, $kickoffId), [
            'calendar_event_form' => [
                '_token' => $token,
                'title' => 'Projekt-Kickoff verlegt',
                'startAt' => '2026-03-10T14:00:00',
                'endAt' => '2026-03-10T15:00:00',
                'location' => 'Raum Bonn',
                'description' => 'Neue Agenda.',
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
                $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(CalendarEvent::class)->find($this->kickoff->getId());
        self::assertInstanceOf(CalendarEvent::class, $updated);
        self::assertSame('Projekt-Kickoff verlegt', $updated->getTitle());
        self::assertSame('Raum Bonn', $updated->getLocation());
        self::assertSame('2026-03-10 14:00', $updated->getStartAt()->format('Y-m-d H:i'));
    }

    public function testEventUpdateWithoutTitleFailsValidation(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();
        $monthKey = $this->kickoff->getStartAt()->format('Y-m');
        $token = $this->modalCsrfToken($kickoffId, $monthKey);

        $this->client->request('POST', sprintf('/calendar/%s/%s/modal', $monthKey, $kickoffId), [
            'calendar_event_form' => [
                '_token' => $token,
                'title' => '',
                'startAt' => '2026-03-10T14:00:00',
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEventCreateAddsEventToSelectedMailbox(): void
    {
        $this->client->loginUser($this->adminUser);

        $salesMailboxId = (string) $this->salesMailbox->getId();

        $crawler = $this->client->request('GET', '/calendar/new', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="calendar_event_form[_token]"]')->attr('value');

        $this->client->request('POST', '/calendar/new', [
            'calendar_event_form' => [
                '_token' => $token,
                'title' => 'Neuer Sales-Termin',
                'startAt' => '2026-05-20T09:00:00',
                'endAt' => '2026-05-20T10:00:00',
                'location' => 'Raum Köln',
                'mailbox' => $salesMailboxId,
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
                $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $created = $this->entityManager->getRepository(CalendarEvent::class)->findOneBy(['title' => 'Neuer Sales-Termin']);
        self::assertInstanceOf(CalendarEvent::class, $created);
        self::assertNotNull($created->getMailbox());
        self::assertTrue($created->getMailbox()->getId()->equals($this->salesMailbox->getId()));
    }

    public function testEventCreateDebugDump(): void
    {
        $this->client->loginUser($this->adminUser);

        $crawler = $this->client->request('GET', '/calendar/new', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="calendar_event_form[_token]"]')->attr('value');

        $this->client->request('POST', '/calendar/new', [
            'calendar_event_form' => [
                '_token' => $token,
                'title' => 'Neuer Sales-Termin',
                'startAt' => '2026-05-20T09:00:00',
                'endAt' => '2026-05-20T10:00:00',
                'location' => 'Raum Köln',
                'mailbox' => (string) $this->salesMailbox->getId(),
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
    }

    public function testEventCreateWithoutTitleFailsValidation(): void
    {
        $this->client->loginUser($this->adminUser);

        $crawler = $this->client->request('GET', '/calendar/new', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="calendar_event_form[_token]"]')->attr('value');

        $this->client->request('POST', '/calendar/new', [
            'calendar_event_form' => [
                '_token' => $token,
                'title' => '',
                'startAt' => '2026-05-20T09:00:00',
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEventDeleteRemovesEventWithValidToken(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();
        $monthKey = $this->kickoff->getStartAt()->format('Y-m');

        $crawler = $this->client->request('GET', sprintf('/calendar/%s/%s', $monthKey, $kickoffId));
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#event-detail a[data-action-id="calendar-delete"]')->attr('data-token');
        self::assertNotEmpty($token);

        $this->client->request('POST', sprintf('/calendar/%s/%s/delete', $monthKey, $kickoffId), [
            '_token' => $token,
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertJsonStringEqualsJsonString('{"success":true}', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(CalendarEvent::class)->find($this->kickoff->getId()));
    }

    public function testEventDeleteWithInvalidTokenIsRejected(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();
        $monthKey = $this->kickoff->getStartAt()->format('Y-m');

        $this->client->request('POST', sprintf('/calendar/%s/%s/delete', $monthKey, $kickoffId), [
            '_token' => 'invalid-token',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(400);
        self::assertNotNull($this->entityManager->getRepository(CalendarEvent::class)->find($this->kickoff->getId()));
    }

    public function testEventDeleteRequiresXhr(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();
        $monthKey = $this->kickoff->getStartAt()->format('Y-m');

        $this->client->request('POST', sprintf('/calendar/%s/%s/delete', $monthKey, $kickoffId));
        self::assertResponseStatusCodeSame(403);
    }

    public function testEventDeleteWithoutXhrRedirectsAfterDelete(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();
        $monthKey = $this->kickoff->getStartAt()->format('Y-m');

        $crawler = $this->client->request('GET', sprintf('/calendar/%s/%s', $monthKey, $kickoffId));
        $token = $crawler->filter('#event-detail a[data-action-id="calendar-delete"]')->attr('data-token');

        $this->client->request('POST', sprintf('/calendar/%s/%s/delete', $monthKey, $kickoffId), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects(sprintf('/calendar/%s', $monthKey));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(CalendarEvent::class)->find($this->kickoff->getId()));
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/calendar');
        self::assertResponseRedirects();
    }

    public function testCalendarMonthGridViewRendersGridWithEvents(): void
    {
        $this->client->loginUser($this->adminUser);

        // Four events on one day to exercise the "+N more" overflow link.
        for ($i = 1; $i <= 4; ++$i) {
            $overflow = (new CalendarEvent())
                ->setTitle(sprintf('Overflow-Termin %d', $i))
                ->setStartAt(new \DateTimeImmutable(sprintf('2026-03-16 0%d:00:00', $i)));
            $overflow->setMailbox($this->mailbox);
            $this->entityManager->persist($overflow);
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/calendar/2026-03', ['view' => 'month']);
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertStringContainsString('Quartals-Review', $content);
        self::assertSelectorExists('.calendar-grid');
        self::assertSelectorExists('.calendar-grid-cell[data-date="2026-03-10"]');
        // March 2026 starts on a Sunday, so the grid shows February days.
        self::assertSelectorExists('.calendar-grid-cell-outside[data-date="2026-02-23"]');
        // Overflow day: exactly three chips plus the more link.
        self::assertSelectorCount(3, '.calendar-grid-cell[data-date="2026-03-16"] .calendar-event-chip');
        self::assertSelectorExists('.calendar-grid-cell[data-date="2026-03-16"] .calendar-event-more');

        // Toolbar: view switcher dropdown left of the previous/today/next navigation.
        self::assertSelectorExists('.calendar-toolbar');
        self::assertSelectorExists('.calendar-toolbar .calendar-view-switcher-dropdown .dropdown-toggle');
        self::assertSelectorExists('.calendar-toolbar .calendar-view-switcher-dropdown .dropdown-item.active');
        self::assertSelectorExists('.calendar-toolbar .calendar-view-switcher-dropdown a[href="/calendar/2026-03?view=day&date=2026-03-01"]');
        self::assertSelectorExists('.calendar-toolbar .calendar-view-switcher-dropdown a[href="/calendar/2026-03?view=week&date=2026-03-01"]');
        self::assertSelectorExists('.calendar-toolbar .calendar-view-switcher-dropdown a[href="/calendar/2026-03?view=list"]');
        self::assertSelectorExists('a[href="/calendar/2026-02?view=month"]');
        self::assertSelectorExists('a[href="/calendar/2026-04?view=month"]');
        self::assertSelectorExists('a[href="/calendar?view=month"]');

        // The mobile list offcanvas only exists in list view.
        self::assertSelectorNotExists('#calendarListOffcanvas');

        // The current month grid marks today's cell.
        $this->client->request('GET', '/calendar', ['view' => 'month']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-grid-cell-today');
    }

    public function testCalendarWeekViewRendersSevenDays(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar/2026-03', ['view' => 'week', 'date' => '2026-03-09']);
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('March 9 – March 15, 2026', $content);
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertSelectorCount(7, '.calendar-grid-header .calendar-grid-weekday');
        self::assertSelectorExists('.calendar-grid-cell[data-date="2026-03-10"]');

        // Period navigation shifts the anchor by one week.
        self::assertSelectorExists('a[href="/calendar/2026-03?view=week&date=2026-03-02"]');
        self::assertSelectorExists('a[href="/calendar/2026-03?view=week&date=2026-03-16"]');
        self::assertSelectorExists('a[href="/calendar?view=week"]');
    }

    public function testCalendarDayViewShowsEventsAndEmptyState(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar/2026-03', ['view' => 'day', 'date' => '2026-03-10']);
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Tuesday, March 10, 2026', $content);
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertStringContainsString('10:00&ndash;11:30', $content);
        self::assertSelectorExists('.calendar-day-view[data-date="2026-03-10"]');

        $this->client->request('GET', '/calendar/2026-03', ['view' => 'day', 'date' => '2026-03-05']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No events on this day.', (string) $this->client->getResponse()->getContent());
    }

    public function testCalendarViewPreferenceIsStoredInCookie(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar', ['view' => 'month']);
        self::assertResponseIsSuccessful();

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $responseCookie) {
            if ('calendar_view' === $responseCookie->getName()) {
                $cookie = $responseCookie;
            }
        }
        self::assertNotNull($cookie);
        self::assertSame('month', $cookie->getValue());

        // The next request without parameter reuses the stored view.
        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-grid');

        // Switching back to the list is persisted as well.
        $this->client->request('GET', '/calendar', ['view' => 'list']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.calendar-grid');
    }

    public function testCalendarInvalidViewParameterFallsBackToListWithoutCookie(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar', ['view' => 'globe']);
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertSelectorExists('form[role="search"][action="/calendar/all"]');
        self::assertSelectorNotExists('.calendar-grid');

        foreach ($this->client->getResponse()->headers->getCookies() as $responseCookie) {
            self::assertNotSame('calendar_view', $responseCookie->getName());
        }
    }

    public function testCalendarGridViewAllowsMonthsWithoutEvents(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar/1999-01', ['view' => 'month']);
        self::assertResponseIsSuccessful();

        self::assertSelectorExists('.calendar-grid');
        self::assertSelectorNotExists('.calendar-event-chip');
        // The list switch falls back to all periods when the month is empty.
        self::assertSelectorExists('a[href="/calendar/all?view=list"]');
    }

    public function testCalendarGridUnknownMonthKeyFailsWithNotFound(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar/2026-99', ['view' => 'month']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarGridRolloverDateParameterIsIgnored(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar/2026-02', ['view' => 'month', 'date' => '2026-02-30']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-grid');
        // Without a valid day the grid shows the month view of 2026-02.
        self::assertStringContainsString('February 2026', (string) $this->client->getResponse()->getContent());
    }

    public function testGridEventDetailRequiresXmlHttpRequest(): void
    {
        $this->client->loginUser($this->adminUser);

        $kickoffId = (string) $this->kickoff->getId();

        $this->client->request('GET', sprintf('/calendar/2026-03/%s/detail', $kickoffId));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/calendar/2026-03/%s/detail', $kickoffId), [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Projekt-Kickoff', $content);
        self::assertStringContainsString('Raum Mainz', $content);
        self::assertSelectorExists('.action-btn-modal');
        self::assertSelectorExists('.calendar-view-switcher-dropdown .dropdown-toggle');

        $crawler = $this->client->request('GET', sprintf('/calendar/2026-03/%s/detail', $kickoffId), ['view' => 'month'], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-view-switcher-dropdown a[href*="view=week"]');
        $active = $crawler->filter('.calendar-view-switcher-dropdown .dropdown-item.active')->attr('title');
        self::assertSame('Month', $active);
    }

    public function testGridEventDetailForUnknownOrForeignEventFails(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('GET', '/calendar/2026-03/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/detail', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNewEventModalPrefillsStartFromGridDate(): void
    {
        $this->client->loginUser($this->adminUser);

        $crawler = $this->client->request('GET', '/calendar/new', ['date' => '2026-03-15'], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $startInput = $crawler->filter('input[name="calendar_event_form[startAt]"]')->attr('value');
        self::assertNotNull($startInput);
        self::assertStringStartsWith('2026-03-15T08:00', $startInput);

        // Invalid day parameters are ignored.
        $crawler = $this->client->request('GET', '/calendar/new', ['date' => 'next-tuesday'], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $emptyValue = $crawler->filter('input[name="calendar_event_form[startAt]"]')->attr('value');
        self::assertTrue(null === $emptyValue || '' === $emptyValue);
    }

    /**
     * Renders the edit modal for the given event and returns the form's CSRF
     * token, emulating the browser flow.
     */
    private function modalCsrfToken(string $eventId, string $monthKey): string
    {
        $crawler = $this->client->request('GET', sprintf('/calendar/%s/%s/modal', $monthKey, $eventId), [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="calendar_event_form[_token]"]')->attr('value');
        self::assertNotEmpty($token);

        return (string) $token;
    }

    private function seedData(): void
    {
        $this->adminUser = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN']);

        $this->mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');

        $this->kickoff = (new CalendarEvent())
            ->setTitle('Projekt-Kickoff')
            ->setLocation('Raum Mainz')
            ->setDescription('Vorstellung des Projektplans.')
            ->setStartAt(new \DateTimeImmutable('2026-03-10 10:00:00'))
            ->setEndAt(new \DateTimeImmutable('2026-03-10 11:30:00'));
        $this->kickoff->setMailbox($this->mailbox);

        $this->review = (new CalendarEvent())
            ->setTitle('Quartals-Review')
            ->setLocation('Raum Online')
            ->setStartAt(new \DateTimeImmutable('2026-04-02 00:00:00'))
            ->setIsAllDay(true);
        $this->review->setMailbox($this->mailbox);

        $otherMailboxEvent = (new CalendarEvent())
            ->setTitle('Fremder Termin')
            ->setStartAt(new \DateTimeImmutable('2026-03-11 09:00:00'));

        $this->salesMailbox = (new Mailbox())
            ->setName('Vertrieb')
            ->setEmail('vertrieb@example.com');
        $salesEvent = (new CalendarEvent())
            ->setTitle('Sales-Treffen')
            ->setLocation('Raum Bonn')
            ->setStartAt(new \DateTimeImmutable('2026-03-12 14:00:00'))
            ->setEndAt(new \DateTimeImmutable('2026-03-12 15:00:00'));
        $salesEvent->setMailbox($this->salesMailbox);

        $this->adminUser->addMailbox($this->mailbox);
        $this->adminUser->addMailbox($this->salesMailbox);

        $this->entityManager->persist($this->adminUser);
        $this->entityManager->persist($this->mailbox);
        $this->entityManager->persist($this->salesMailbox);
        $this->entityManager->persist($this->kickoff);
        $this->entityManager->persist($this->review);
        $this->entityManager->persist($otherMailboxEvent);
        $this->entityManager->persist($salesEvent);
        $this->entityManager->flush();
    }
}
