<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Sync;

use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Repository\SyncRunRepository;
use AvalynxOffice\Service\ConnectorPluginRegistry;

/**
 * Executes one pass of the sync service: fetches mails, contacts and
 * calendar entries from every configured connector plugin and records the
 * run for the system status in the admin area.
 */
final readonly class SyncService
{
    public function __construct(
        private ConnectorPluginRegistry $pluginRegistry,
        private ConnectorRepository $connectorRepository,
        private SyncRunRepository $syncRunRepository,
    ) {
    }

    /**
     * Runs a single pass and records it. The pass is recorded as "running"
     * before the work starts, so a crashed process leaves a visible trace.
     *
     * @param array{mails?: int, contacts?: int, calendar?: int, oldestFirst?: bool} $limits maximum entries per module and connector
     */
    public function run(array $limits, string $trigger = SyncRun::TRIGGER_DAEMON): SyncRun
    {
        $run = new SyncRun();
        $run->setTrigger($trigger);
        $run->setStatus(SyncRun::STATUS_RUNNING);
        $this->syncRunRepository->save($run);

        try {
            $mails = $this->syncMails($run, max(0, $limits['mails'] ?? 0), (bool) ($limits['oldestFirst'] ?? true));
            $run->setMailsImported($mails['imported']);
            $run->setMailsSkipped($mails['skipped']);
            $run->setMailsErrors($mails['errors']);

            $contacts = $this->syncContacts($run, max(0, $limits['contacts'] ?? 0));
            $run->setContactsCount($contacts['imported']);

            $calendar = $this->syncCalendar($run, max(0, $limits['calendar'] ?? 0));
            $run->setCalendarCount($calendar['imported']);

            $run->setStatus(SyncRun::STATUS_SUCCESS);
        } catch (\Throwable $exception) {
            $run->setStatus(SyncRun::STATUS_ERROR);
            $run->setMessage($exception->getMessage());
        }

        $run->setFinishedAt(new \DateTimeImmutable());
        $this->syncRunRepository->save($run);

        return $run;
    }

    /**
     * Fetches mails from every connector, oldest entries first.
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    private function syncMails(SyncRun $run, int $limit, bool $oldestFirst): array
    {
        $totals = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

        if (0 === $limit) {
            return $totals;
        }

        foreach ($this->connectorRepository->findBy([], ['name' => 'ASC']) as $connector) {
            $fetchers = $this->pluginRegistry->getFetchers($connector);
            if ([] === $fetchers) {
                continue;
            }

            foreach ($fetchers as $fetcher) {
                $request = new ConnectorFetchRequest();
                $request->limit = $limit;
                $request->oldestFirst = $oldestFirst;

                $result = $fetcher['plugin']->fetch($connector, $fetcher['config'], $request);
                $totals['imported'] += $result['imported'];
                $totals['skipped'] += $result['skipped'];
                $totals['errors'] += $result['errors'];
            }
        }

        return $totals;
    }

    /**
     * Fetches contacts from every connector that provides a contact fetcher.
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    private function syncContacts(SyncRun $run, int $limit): array
    {
        $totals = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

        if (0 === $limit) {
            return $totals;
        }

        foreach ($this->connectorRepository->findBy([], ['name' => 'ASC']) as $connector) {
            $fetchers = $this->pluginRegistry->getContactFetchers($connector);
            if ([] === $fetchers) {
                continue;
            }

            foreach ($fetchers as $fetcher) {
                $request = new ConnectorFetchRequest();
                $request->limit = $limit;

                $result = $fetcher['plugin']->fetchContacts($connector, $fetcher['config'], $request);
                $totals['imported'] += $result['imported'];
                $totals['skipped'] += $result['skipped'];
                $totals['errors'] += $result['errors'];
            }
        }

        return $totals;
    }

    /**
     * Fetches calendar entries from every connector that provides a calendar
     * fetcher.
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    private function syncCalendar(SyncRun $run, int $limit): array
    {
        $totals = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

        if (0 === $limit) {
            return $totals;
        }

        foreach ($this->connectorRepository->findBy([], ['name' => 'ASC']) as $connector) {
            $fetchers = $this->pluginRegistry->getCalendarFetchers($connector);
            if ([] === $fetchers) {
                continue;
            }

            foreach ($fetchers as $fetcher) {
                $request = new ConnectorFetchRequest();
                $request->limit = $limit;

                $result = $fetcher['plugin']->fetchCalendar($connector, $fetcher['config'], $request);
                $totals['imported'] += $result['imported'];
                $totals['skipped'] += $result['skipped'];
                $totals['errors'] += $result['errors'];
            }
        }

        return $totals;
    }
}
