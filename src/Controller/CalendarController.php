<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Form\CalendarEventFormType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\CalendarEventRepository;
use AvalynxOffice\Repository\MailboxRepository;
use AvalynxOffice\Service\Mail\UserMailboxProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class CalendarController extends AbstractController
{
    private const array VIEWS = ['list', 'day', 'week', 'month'];
    private const string VIEW_COOKIE = 'calendar_view';

    public function __construct(
        private readonly UserMailboxProvider $mailboxProvider,
        private readonly CalendarEventRepository $eventRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly TranslatorInterface $translator,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route(path: '/calendar', name: 'calendar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderCalendar('all', null, $request);
    }

    #[Route(path: '/calendar/{monthKey}', name: 'calendar_month', methods: ['GET'], requirements: ['monthKey' => 'all|\d{4}-\d{2}'])]
    public function month(string $monthKey, Request $request): Response
    {
        return $this->renderCalendar($monthKey, null, $request);
    }

    #[Route(path: '/calendar/{monthKey}/{eventId}', name: 'calendar_month_event', methods: ['GET'], requirements: ['monthKey' => 'all|\d{4}-\d{2}', 'eventId' => '[0-9a-fA-F\-]{36}'])]
    public function monthEvent(string $monthKey, string $eventId, Request $request): Response
    {
        return $this->renderCalendar($monthKey, $eventId, $request);
    }

    #[Route(path: '/calendar/new', name: 'calendar_new_modal', methods: ['GET'])]
    public function newModal(Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $user = $this->getUser();
        \assert($user instanceof User);

        $event = new CalendarEvent();
        $prefillDate = $this->resolveDayDate($request->query->getString('date'));
        if (null !== $prefillDate) {
            $event->setStartAt($prefillDate->setTime(8, 0));
        }

        $form = $this->createForm(CalendarEventFormType::class, $event, [
            'action' => $this->generateUrl('calendar_new_modal'),
            'mailboxes' => $this->mailboxProvider->forUser($user),
        ]);

        return $this->render('calendar/_modal_form.html.twig', [
            'form' => $form,
            'title' => $this->translator->trans('calendar.new'),
        ]);
    }

    #[Route(path: '/calendar/new', name: 'calendar_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $user = $this->getUser();
        \assert($user instanceof User);

        $event = new CalendarEvent();
        $form = $this->createForm(CalendarEventFormType::class, $event, [
            'action' => $this->generateUrl('calendar_new_modal'),
            'mailboxes' => $this->mailboxProvider->forUser($user),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderModalForm($form, $this->translator->trans('calendar.new'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mailbox = $form->get('mailbox')->getData();
        \assert($mailbox instanceof Mailbox);

        $this->licenseEnforcement->assertCanPerform(LicenseOperation::CALENDAR_WRITE);
        $event->setUid(\Symfony\Component\Uid\Uuid::v7()->toRfc4122());

        $this->eventRepository->createFromForm($mailbox, $event);

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/calendar/{monthKey}/{eventId}/modal', name: 'calendar_edit_modal', methods: ['GET'], requirements: ['monthKey' => 'all|\d{4}-\d{2}', 'eventId' => '[0-9a-fA-F\-]{36}'])]
    public function editModal(string $monthKey, string $eventId, Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $event = $this->resolveEvent($eventId);

        $form = $this->createForm(CalendarEventFormType::class, $event, [
            'action' => $this->generateUrl('calendar_edit_modal', ['monthKey' => $monthKey, 'eventId' => $eventId]),
        ]);

        return $this->render('calendar/_modal_form.html.twig', [
            'form' => $form,
            'title' => $this->translator->trans('calendar.edit'),
        ]);
    }

    #[Route(path: '/calendar/{monthKey}/{eventId}/modal', name: 'calendar_update', methods: ['POST'], requirements: ['monthKey' => 'all|\d{4}-\d{2}', 'eventId' => '[0-9a-fA-F\-]{36}'])]
    public function update(string $monthKey, string $eventId, Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $event = $this->resolveEvent($eventId);

        $form = $this->createForm(CalendarEventFormType::class, $event, [
            'action' => $this->generateUrl('calendar_edit_modal', ['monthKey' => $monthKey, 'eventId' => $eventId]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderModalForm($form, $this->translator->trans('calendar.edit'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->licenseEnforcement->assertCanPerform(LicenseOperation::CALENDAR_WRITE);
        $this->eventRepository->save($event);

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/calendar/{monthKey}/{eventId}/delete', name: 'calendar_event_delete', methods: ['POST'], requirements: ['monthKey' => 'all|\d{4}-\d{2}', 'eventId' => '[0-9a-fA-F\-]{36}'])]
    public function delete(string $monthKey, string $eventId, Request $request): Response
    {
        $event = $this->resolveEvent($eventId);

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('delete_event_'.$eventId, $token)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => $this->translator->trans('common.invalid_token')], Response::HTTP_BAD_REQUEST);
            }

            throw new AccessDeniedException('Invalid calendar event delete token.');
        }

        $this->licenseEnforcement->assertCanPerform(LicenseOperation::CALENDAR_WRITE);
        $this->eventRepository->delete($event);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        $user = $this->getUser();
        \assert($user instanceof User);
        $groups = $this->buildGroups($this->eventRepository->findEventsForMailboxes($this->mailboxProvider->forUser($user)));

        return $this->redirectToRoute('calendar_month', ['monthKey' => isset($groups[$monthKey]) ? $monthKey : 'all']);
    }

    #[Route(path: '/calendar/{monthKey}/{eventId}/detail', name: 'calendar_event_detail', methods: ['GET'], requirements: ['monthKey' => 'all|\d{4}-\d{2}', 'eventId' => '[0-9a-fA-F\-]{36}'])]
    public function eventDetail(string $monthKey, string $eventId, Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $user = $this->getUser();
        \assert($user instanceof User);
        $mailboxes = $this->mailboxProvider->forUser($user);
        $activeMailbox = $this->resolveMailbox($request, $mailboxes);
        $scopeMailboxes = null !== $activeMailbox ? [$activeMailbox] : $mailboxes;
        $search = trim((string) ($request->query->get('q', '') ?? ''));

        $view = $this->resolveView($request);
        $groups = $this->buildGroups($this->eventRepository->findEventsForMailboxes($scopeMailboxes));
        $viewLinks = $this->viewLinks($view, $this->resolveAnchor($monthKey, $request), $groups, $this->mailboxId($activeMailbox), $search);

        return $this->render('calendar/_detail.html.twig', [
            'activeEvent' => $this->resolveEvent($eventId),
            'activeMonth' => $monthKey,
            'activeMailboxId' => $this->mailboxId($activeMailbox),
            'view' => $view,
            'viewLinks' => $viewLinks,
        ]);
    }

    private function renderCalendar(string $monthKey, ?string $activeEventId, ?Request $request = null): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $request ??= Request::createFromGlobals();

        $mailboxes = $this->mailboxProvider->forUser($user);
        $activeMailbox = $this->resolveMailbox($request, $mailboxes);
        $scopeMailboxes = null !== $activeMailbox ? [$activeMailbox] : $mailboxes;
        $search = trim((string) ($request->query->get('q', '') ?? ''));

        // The event detail URL always renders the three-pane list layout so
        // the requested event is guaranteed to be selectable.
        $activeView = null !== $activeEventId ? 'list' : $this->resolveView($request);

        $allEvents = $this->eventRepository->findEventsForMailboxes($scopeMailboxes);
        $groups = $this->buildGroups($allEvents);

        if ('list' === $activeView && 'all' !== $monthKey && !isset($groups[$monthKey])) {
            throw new NotFoundHttpException('Calendar month not found.');
        }

        $anchor = $this->resolveAnchor($monthKey, $request);
        $events = '' !== $search
            ? $this->eventRepository->findEventsForMailboxes($scopeMailboxes, $search)
            : $allEvents;

        $data = [
            'mailboxes' => $mailboxes,
            'activeMailbox' => $activeMailbox,
            'activeMailboxId' => $this->mailboxId($activeMailbox),
            'groups' => $groups,
            'activeMonth' => $monthKey,
            'events' => [],
            'activeEvent' => null,
            'search' => $search,
            'totalEvents' => count($allEvents),
            'view' => $activeView,
            'grid' => null,
            'viewLinks' => $this->viewLinks($activeView, $anchor, $groups, $this->mailboxId($activeMailbox), $search),
            'navigation' => null,
            'monthLabel' => 'all' === $monthKey ? null : $this->monthLabel($monthKey),
        ];

        $gridAnchor = 'month' === $activeView ? $anchor->modify('first day of this month') : $anchor;

        if ('list' === $activeView) {
            if ('all' !== $monthKey) {
                $events = array_values(array_filter(
                    $events,
                    static fn (CalendarEvent $event): bool => self::groupKeyFor($event) === $monthKey,
                ));
            }

            $activeEvent = null;
            if (null !== $activeEventId) {
                foreach ($events as $event) {
                    if ((string) $event->getId() === $activeEventId) {
                        $activeEvent = $event;

                        break;
                    }
                }
            }
            $activeEvent ??= $events[0] ?? null;
            $data['activeEvent'] = $activeEvent;
            $data['events'] = $events;
        } else {
            $data['grid'] = $this->buildGrid($activeView, $gridAnchor, $events);
            $data['navigation'] = $this->navigationUrls($activeView, $gridAnchor, $this->mailboxId($activeMailbox), $search);
        }

        $response = $this->render('calendar/index.html.twig', $data);

        if (null !== $activeEventId) {
            return $response;
        }

        $requestedView = $request->query->getString('view');
        if (in_array($requestedView, self::VIEWS, true)) {
            $response->headers->setCookie(Cookie::create(
                self::VIEW_COOKIE,
                $requestedView,
                new \DateTimeImmutable('+1 year'),
                '/',
                null,
                $request->isSecure(),
            ));
        }

        return $response;
    }

    /**
     * Active calendar view: explicit query parameter wins, otherwise the
     * stored preference (cookie) and finally the list as fallback.
     */
    private function resolveView(Request $request): string
    {
        $requestedView = $request->query->getString('view');
        if (in_array($requestedView, self::VIEWS, true)) {
            return $requestedView;
        }

        $storedView = $request->cookies->getString(self::VIEW_COOKIE, '');

        return in_array($storedView, self::VIEWS, true) ? $storedView : 'list';
    }

    /**
     * Anchor date of the visible period: an explicit day parameter wins,
     * then the first day of the requested month and finally today.
     */
    private function resolveAnchor(string $monthKey, Request $request): \DateTimeImmutable
    {
        $day = $this->resolveDayDate($request->query->getString('date'));
        if (null !== $day) {
            return $day;
        }

        if ('all' === $monthKey) {
            return new \DateTimeImmutable('today');
        }

        $month = $this->resolveDayDate($monthKey.'-01');
        if (null === $month || $month->format('Y-m') !== $monthKey) {
            throw new NotFoundHttpException('Calendar month not found.');
        }

        return $month;
    }

    /**
     * Parses a strict Y-m-d date string, rejecting rollovers such as
     * 2026-02-30.
     */
    private function resolveDayDate(string $value): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * Links to all non-active views, preserving the current period, mailbox
     * scope and search. Periods without events fall back to the full list.
     *
     * @param array<string, array{label: string, count: int}> $groups
     *
     * @return array<string, string>
     */
    private function viewLinks(string $activeView, \DateTimeImmutable $anchor, array $groups, string $mailboxId, string $search): array
    {
        $common = $this->scopeParams($mailboxId, $search);
        $anchorMonth = $anchor->format('Y-m');

        $links = [];
        foreach (self::VIEWS as $targetView) {
            if ($targetView === $activeView) {
                continue;
            }

            $params = match ($targetView) {
                'list' => array_merge([
                    'monthKey' => isset($groups[$anchorMonth]) ? $anchorMonth : 'all',
                    'view' => 'list',
                ], $common),
                'month' => array_merge(['monthKey' => $anchorMonth, 'view' => 'month'], $common),
                'day', 'week' => array_merge([
                    'monthKey' => $anchorMonth,
                    'view' => $targetView,
                    'date' => $anchor->format('Y-m-d'),
                ], $common),
            };

            $links[$targetView] = $this->generateUrl('calendar_month', $params);
        }

        return $links;
    }

    /**
     * Previous/today/next navigation for the visible period.
     *
     * @return array{prev: string, today: string, next: string}
     */
    private function navigationUrls(string $view, \DateTimeImmutable $anchor, string $mailboxId, string $search): array
    {
        $common = $this->scopeParams($mailboxId, $search);

        [$previous, $next] = match ($view) {
            'day' => [$anchor->modify('-1 day'), $anchor->modify('+1 day')],
            'week' => [$anchor->modify('-7 days'), $anchor->modify('+7 days')],
            default => [$anchor->modify('first day of previous month'), $anchor->modify('first day of next month')],
        };

        $periodParams = static fn (\DateTimeImmutable $date): array => array_merge(
            array_filter([
                'monthKey' => $date->format('Y-m'),
                'view' => $view,
                'date' => 'month' === $view ? null : $date->format('Y-m-d'),
            ]),
            $common,
        );

        return [
            'prev' => $this->generateUrl('calendar_month', $periodParams($previous)),
            'today' => $this->generateUrl('calendar_index', array_merge(['view' => $view], $common)),
            'next' => $this->generateUrl('calendar_month', $periodParams($next)),
        ];
    }

    /**
     * Visible grid cells for the day, week or month view. Events are
     * grouped per day, ordered with all-day events first.
     *
     * @param list<CalendarEvent> $events
     *
     * @return array<string, mixed>
     */
    private function buildGrid(string $view, \DateTimeImmutable $anchor, array $events): array
    {
        $byDate = [];
        foreach ($events as $event) {
            $byDate[$event->getStartAt()->format('Y-m-d')][] = $event;
        }

        $today = new \DateTimeImmutable('today');

        $cell = static fn (\DateTimeImmutable $date): array => [
            'date' => $date,
            'isToday' => $date->format('Y-m-d') === $today->format('Y-m-d'),
            'events' => $byDate[$date->format('Y-m-d')] ?? [],
        ];

        if ('day' === $view) {
            return ['anchor' => $anchor, 'day' => $cell($anchor)];
        }

        $weekStart = static fn (\DateTimeImmutable $date): \DateTimeImmutable => $date->modify('monday this week');

        if ('week' === $view) {
            $start = $weekStart($anchor);
            $days = [];
            for ($offset = 0; $offset < 7; ++$offset) {
                $days[] = $cell($start->modify(sprintf('+%d days', $offset)));
            }

            return ['anchor' => $anchor, 'start' => $start, 'end' => $start->modify('+6 days'), 'days' => $days];
        }

        $cursor = $weekStart($anchor);
        $weeks = [];
        for ($week = 0; $week < 6; ++$week) {
            $days = [];
            for ($offset = 0; $offset < 7; ++$offset) {
                $day = $cell($cursor);
                $day['inMonth'] = $cursor->format('Y-m') === $anchor->format('Y-m');
                $days[] = $day;
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $days;
        }

        while (count($weeks) > 1 && !in_array(true, array_column($weeks[count($weeks) - 1], 'inMonth'), true)) {
            array_pop($weeks);
        }

        return ['anchor' => $anchor, 'weeks' => $weeks];
    }

    /**
     * @return array<string, string>
     */
    private function scopeParams(string $mailboxId, string $search): array
    {
        $params = [];

        if ('all' !== $mailboxId) {
            $params['mailbox'] = $mailboxId;
        }

        if ('' !== $search) {
            $params['q'] = $search;
        }

        return $params;
    }

    /**
     * Finds an event by id among the events of the user's mailboxes.
     */
    private function resolveEvent(string $eventId): CalendarEvent
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        try {
            $uuid = UuidV7::fromString($eventId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Event not found.');
        }

        $event = $this->eventRepository->find($uuid);

        if (
            null === $event
            || null === $event->getMailbox()
            || !in_array($event->getMailbox(), $this->mailboxProvider->forUser($user), true)
        ) {
            throw new NotFoundHttpException('Event not found.');
        }

        return $event;
    }

    /**
     * @param FormInterface<CalendarEvent> $form
     */
    private function renderModalForm(FormInterface $form, string $title, int $status): Response
    {
        return $this->render('calendar/_modal_form.html.twig', [
            'form' => $form,
            'title' => $title,
        ], new Response(null, $status));
    }

    private function requireXmlHttpRequest(Request $request): void
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Resolves the mailbox scope from the request. A mailbox the user has no
     * access to is rejected instead of silently ignored.
     *
     * @param list<Mailbox> $mailboxes
     */
    private function resolveMailbox(?Request $request, array $mailboxes): ?Mailbox
    {
        $mailboxId = (string) ($request?->query->get('mailbox', 'all') ?? 'all');

        if ('all' === $mailboxId || '' === $mailboxId) {
            return null;
        }

        $mailbox = $this->mailboxRepository->find($mailboxId);

        if (null === $mailbox || !in_array($mailbox, $mailboxes, true)) {
            throw new AccessDeniedException();
        }

        return $mailbox;
    }

    private function mailboxId(?Mailbox $mailbox): string
    {
        return null !== $mailbox ? (string) $mailbox->getId() : 'all';
    }

    /**
     * Month groups with labels and event counts, built from the unfiltered
     * event list so counts stay stable while searching.
     *
     * @param list<CalendarEvent> $events
     *
     * @return array<string, array{label: string, count: int}>
     */
    private function buildGroups(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $key = self::groupKeyFor($event);
            $groups[$key] ??= ['label' => $this->monthLabel($key), 'count' => 0];
            ++$groups[$key]['count'];
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    private function monthLabel(string $monthKey): string
    {
        [$year, $month] = array_map(
            static fn (string $part): int => (int) $part,
            explode('-', $monthKey),
        );

        return $this->translator->trans('calendar.month', [
            '%month%' => $this->translator->trans('calendar.months.'.$month),
            '%year%' => (string) $year,
        ]);
    }

    private static function groupKeyFor(CalendarEvent $event): string
    {
        return $event->getStartAt()->format('Y-m');
    }
}
