<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Repository\CalendarEventRepository;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use garethp\ews\API\Type\AbsoluteMonthlyRecurrencePatternType;
use garethp\ews\API\Type\AbsoluteYearlyRecurrencePatternType;
use garethp\ews\API\Type\CalendarItemType;
use garethp\ews\API\Type\DailyRecurrencePatternType;
use garethp\ews\API\Type\RelativeMonthlyRecurrencePatternType;
use garethp\ews\API\Type\RelativeYearlyRecurrencePatternType;
use garethp\ews\API\Type\WeeklyRecurrencePatternType;
use JBSNewMedia\ExchangeConnectorBundle\Plugin\ExchangeConnectorPlugin;

/**
 * Fetches the calendar entries of the Exchange calendar (Eingang) into the
 * local CalendarEvent entities. Existing events are recognized by their
 * EWS UID and skipped. Recurrence patterns are mapped to the local
 * recurrence fields.
 */
final class EwsCalendarFetcher extends AbstractEwsFetcher
{
    private const array DAY_OF_WEEK = [
        'Sunday' => 0,
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
    ];

    private const array DAY_OF_WEEK_INDEX = [
        'First' => 1,
        'Second' => 2,
        'Third' => 3,
        'Fourth' => 4,
        'Last' => 5,
    ];

    private const array MONTHS = [
        'January' => 1,
        'February' => 2,
        'March' => 3,
        'April' => 4,
        'May' => 5,
        'June' => 6,
        'July' => 7,
        'August' => 8,
        'September' => 9,
        'October' => 10,
        'November' => 11,
        'December' => 12,
    ];

    private const array BUSY_STATUS = [
        'Free' => 0,
        'Tentative' => 1,
        'Busy' => 2,
        'OOF' => 3,
    ];

    private const array SENSITIVITY = [
        'Normal' => 0,
        'Personal' => 1,
        'Private' => 2,
        'Confidential' => 3,
    ];

    public function __construct(
        private readonly EwsClientFactory $clientFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarEventRepository $calendarEventRepository,
        private readonly ConnectorConfigRepository $connectorConfigRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function fetchCalendar(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        $this->bindRequest($request);
        if ('' === self::stringValue($config, 'ewsHost')
            || '' === self::stringValue($config, 'ewsUsername')
            || '' === self::stringValue($config, 'ewsPassword')) {
            $this->log('Exchange configuration incomplete.', 'error');

            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $delete = self::deleteAfter($config, $request);
        $totals = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        try {
            $this->log('Connecting to the Exchange calendar (EWS) ...');
            $client = $this->clientFactory->create($config);
            $this->log('Connection established successfully.');
            $listing = $client->listItemRefs('calendar', 'calendar:Start', true, $request->limit, $connector->getFetchFrom());
            $itemRefs = $listing['items'];
            $this->log(sprintf('%d appointments found.', count($itemRefs)));
            foreach ($itemRefs as $ref) {
                $this->processItem($client, $connector, $ref, $delete, $totals);
            }
            $this->updateTotals($connector, $listing['total']);
        } catch (\Throwable $exception) {
            $this->logError('Error during the Exchange calendar sync: '.$exception->getMessage(), $exception);
            ++$totals['errors'];
        }

        return $totals;
    }

    /**
     * @param array{imported: int, skipped: int, errors: int} $totals
     */
    private function processItem(EwsApiClient $client, Connector $connector, EwsItemRef $ref, bool $delete, array &$totals): void
    {
        try {
            $item = $client->getItem($ref);
        } catch (\Throwable $exception) {
            $this->logError(sprintf('Could not load appointment %s: %s', $ref->id, $exception->getMessage()), $exception);
            ++$totals['errors'];

            return;
        }
        if (!$item instanceof CalendarItemType) {
            $this->logError(sprintf('Item %s is not a calendar entry. Skipping.', $ref->id));
            ++$totals['errors'];

            return;
        }
        $uid = self::prop($item, 'UID');
        if (null === $uid) {
            $this->logError(sprintf('Appointment %s has no UID. Skipping.', $ref->id));
            ++$totals['errors'];

            return;
        }
        $existing = $this->calendarEventRepository->findOneBy(['uid' => $uid]);
        if ($existing instanceof CalendarEvent) {
            $this->log('Appointment already imported. Skipping.', 'comment');
            ++$totals['skipped'];

            return;
        }
        $start = EwsTypeHelper::dateTime(self::prop($item, 'start'));
        if (null === $start) {
            $this->logError(sprintf('Appointment %s has no valid start date. Skipping.', $ref->id));
            ++$totals['errors'];

            return;
        }
        $event = new CalendarEvent();
        $event->setTitle(self::prop($item, 'subject') ?? '(ohne Titel)');
        $event->setUid($uid);
        $event->setStartAt($start);
        $event->setEndAt(EwsTypeHelper::dateTime(self::prop($item, 'end')));
        $event->setLocation(self::prop($item, 'location'));
        $event->setDescription(self::bodyText($item));
        $event->setIsAllDay(self::boolProp($item, 'isAllDayEvent'));
        $event->setTimezone(self::prop($item, 'startTimeZoneId'));
        $event->setAccount($connector->getDefaultAccount());
        $this->applyRecurrence($event, $item);
        $busy = self::BUSY_STATUS[self::prop($item, 'legacyFreeBusyStatus') ?? ''] ?? null;
        $event->setBusyStatus($busy);
        $sensitivity = self::SENSITIVITY[self::prop($item, 'sensitivity') ?? ''] ?? null;
        $event->setSensitivity($sensitivity);
        $reminder = self::prop($item, 'reminderMinutesBeforeStart');
        if (null !== $reminder && self::boolProp($item, 'reminderIsSet')) {
            $event->setReminder((int) $reminder);
        }
        $event->setDtstamp(EwsTypeHelper::dateTime(self::prop($item, 'dateTimeStamp')));
        $this->entityManager->persist($event);
        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            if (!$this->entityManager->isOpen()) {
                throw $exception;
            }
            $this->logError(sprintf('Appointment %s could not be saved: %s', $uid, $exception->getMessage()), $exception);
            ++$totals['errors'];

            return;
        }
        if ($delete) {
            try {
                $client->deleteItem($ref);
                $this->log('Appointment moved to Deleted Items on the Exchange server.', 'comment');
            } catch (\Throwable $exception) {
                $this->logError(sprintf('Appointment %s could not be deleted: %s', $uid, $exception->getMessage()), $exception);
                ++$totals['errors'];
            }
        }
        $this->log(sprintf('Appointment "%s" imported.', $event->getTitle()), 'info');
        ++$totals['imported'];
    }

    /**
     * Maps the EWS recurrence pattern to the local recurrence fields
     * (0 = daily, 1 = weekly, 2 = monthly on the Nth day, 3 = monthly on the
     * Nth weekday, 4 = yearly on the Nth day, 5 = yearly on the Nth weekday).
     */
    private function applyRecurrence(CalendarEvent $event, CalendarItemType $item): void
    {
        $recurrence = $item->getRecurrence();
        if (!is_object($recurrence)) {
            return;
        }
        $pattern = self::firstOf($recurrence, ['dailyRecurrence', 'weeklyRecurrence', 'absoluteMonthlyRecurrence', 'relativeMonthlyRecurrence', 'absoluteYearlyRecurrence', 'relativeYearlyRecurrence']);
        $range = self::firstOf($recurrence, ['endDateRecurrence', 'numberedRecurrence', 'noEndRecurrence']);
        if (null === $pattern) {
            return;
        }
        if (null !== $range && method_exists($range, 'getEndDate')) {
            $event->setRecurrenceUntil(EwsTypeHelper::dateTime(self::prop($range, 'endDate')));
        }
        $type = match ($pattern::class) {
            DailyRecurrencePatternType::class => 0,
            WeeklyRecurrencePatternType::class => 1,
            AbsoluteMonthlyRecurrencePatternType::class => 2,
            RelativeMonthlyRecurrencePatternType::class => 3,
            AbsoluteYearlyRecurrencePatternType::class => 4,
            RelativeYearlyRecurrencePatternType::class => 5,
            default => null,
        };
        if (null === $type) {
            return;
        }
        $event->setRecurrenceType($type);
        $event->setRecurrenceInterval(self::intProp($pattern, 'interval'));
        $days = self::daysOfWeek($pattern);
        $event->setRecurrenceDayOfWeek($days);
        $event->setRecurrenceDayOfMonth(self::intProp($pattern, 'dayOfMonth'));
        $event->setRecurrenceWeekOfMonth(self::dayOfWeekIndex($pattern));
        $event->setRecurrenceMonthOfYear(self::monthOfYear($pattern));
    }

    /**
     * @param list<string> $getters
     */
    private static function firstOf(object $recurrence, array $getters): ?object
    {
        foreach ($getters as $getter) {
            if (!method_exists($recurrence, 'get'.ucfirst($getter))) {
                continue;
            }
            $value = $recurrence->{'get'.ucfirst($getter)}();
            if (is_object($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function daysOfWeek(object $pattern): ?int
    {
        if (!method_exists($pattern, 'getDaysOfWeek')) {
            return null;
        }
        $days = $pattern->getDaysOfWeek();
        if (!is_array($days) || 1 !== count($days)) {
            return null;
        }
        $day = $days[0];
        if (!is_string($day)) {
            return null;
        }

        return self::DAY_OF_WEEK[$day] ?? null;
    }

    private static function dayOfWeekIndex(object $pattern): ?int
    {
        if (!method_exists($pattern, 'getDayOfWeekIndex')) {
            return null;
        }
        $index = $pattern->getDayOfWeekIndex();
        if (!is_string($index)) {
            return null;
        }

        return self::DAY_OF_WEEK_INDEX[$index] ?? null;
    }

    private static function monthOfYear(object $pattern): ?int
    {
        if (!method_exists($pattern, 'getMonth')) {
            return null;
        }
        $month = $pattern->getMonth();
        if (!is_string($month)) {
            return null;
        }

        return self::MONTHS[$month] ?? null;
    }

    private static function bodyText(CalendarItemType $item): ?string
    {
        $body = $item->getBody();
        if (!is_object($body)) {
            return null;
        }
        $text = EwsTypeHelper::entryValue($body);
        if (null === $text) {
            return null;
        }
        $bodyType = 'Text';
        if (method_exists($body, 'getBodyType')) {
            $rawType = $body->getBodyType();
            if (is_string($rawType)) {
                $bodyType = $rawType;
            }
        }
        if ('HTML' === $bodyType) {
            $text = preg_replace('/\s+/', ' ', (string) strip_tags($text));
        }

        return mb_substr(trim((string) $text), 0, 5000);
    }

    private static function prop(object $object, string $getter): ?string
    {
        $method = 'get'.ucfirst($getter);
        if (!method_exists($object, $method)) {
            return null;
        }
        $value = $object->{$method}();
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }

    private static function intProp(object $object, string $getter): ?int
    {
        $method = 'get'.ucfirst($getter);
        if (!method_exists($object, $method)) {
            return null;
        }
        $value = $object->{$method}();

        return is_numeric($value) ? (int) $value : null;
    }

    private static function boolProp(object $object, string $getter): bool
    {
        $method = 'get'.ucfirst($getter);
        if (!method_exists($object, $method)) {
            return false;
        }
        $value = $object->{$method}();
        if (is_bool($value)) {
            return $value;
        }

        return 'true' === $value || '1' === $value;
    }

    /**
     * Works on the stored (encrypted) config so no decrypted secret is
     * written back.
     */
    private function updateTotals(Connector $connector, int $total): void
    {
        try {
            $entity = $this->connectorConfigRepository->findOneBy(['connector' => $connector, 'pluginKey' => ExchangeConnectorPlugin::KEY]);
            if (!$entity instanceof ConnectorConfig) {
                return;
            }
            $stored = $entity->getConfig();
            $stored['calendar_total'] = $total;
            $entity->setConfig($stored);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logError('Could not update the fetch status: '.$exception->getMessage(), $exception);
        }
    }
}
