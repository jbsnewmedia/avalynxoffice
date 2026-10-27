<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\ExchangeConnectorBundle\Plugin\ExchangeConnectorPlugin;

/**
 * Fetches the mails of the Exchange mailbox (Eingang): lists the inbox via
 * FindItem, loads every not-yet-imported message as raw RFC822 via GetItem
 * (MimeContent) and imports it like the POP3 connector. Optionally the
 * messages are moved to the Exchange "Deleted Items" folder after a
 * successful import.
 */
final class EwsMailFetcher extends AbstractEwsFetcher
{
    public function __construct(
        private readonly EwsClientFactory $clientFactory,
        private readonly RawMailImporter $importer,
        private readonly EmailRepository $emailRepo,
        private readonly ConnectorConfigRepository $connectorConfigRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function fetch(Connector $connector, array $config, ConnectorFetchRequest $request): array
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
            $this->log('Connecting to the Exchange server (EWS) ...');
            $client = $this->clientFactory->create($config);
            $this->log('Connection established successfully.');
            $fetchFrom = $connector->getFetchFrom();
            $oldestFirst = null !== $fetchFrom ? true : $request->oldestFirst;
            if (null !== $fetchFrom) {
                $this->log(sprintf('"Fetch from" is set (%s): fetching from the oldest entry at or after the date.', $fetchFrom->format('d.m.Y')));
            }
            // Diff against everything already imported: known messages
            // never occupy the fetch window.
            $known = $this->emailRepo->importKeysForConnector($connector);
            $importKeyPrefix = (string) $connector->getId().':';
            $isKnown = static fn (EwsItemRef $ref): bool => isset($known[$importKeyPrefix.$ref->id]);
            $listing = $client->listItemRefs('inbox', 'item:DateTimeReceived', $oldestFirst, $request->limit, $fetchFrom, $isKnown);
            if ($listing['budgetExhausted']) {
                $this->log(sprintf('Scan budget reached after %d pages without filling the batch. Consider setting a "fetch from" date for large mailboxes.', 20), 'comment');
            }
            $itemRefs = $listing['items'];
            $this->log(sprintf('%d messages found.', count($itemRefs)));
            foreach ($itemRefs as $ref) {
                $this->processItem($client, $connector, $ref, $delete, $totals);
            }
            $this->updateMailsTotal($connector, $config, $listing['total'], $delete, $totals['imported']);
        } catch (\Throwable $exception) {
            $this->logError('Error during the Exchange mail sync: '.$exception->getMessage(), $exception);
            ++$totals['errors'];
        }

        return $totals;
    }

    /**
     * @param array{imported: int, skipped: int, errors: int} $totals
     */
    private function processItem(EwsApiClient $client, Connector $connector, EwsItemRef $ref, bool $delete, array &$totals): void
    {
        $importKey = (string) $connector->getId().':'.$ref->id;
        if ($this->emailRepo->findOneBy(['importKey' => $importKey]) instanceof Email) {
            $this->log('Already imported. Skipping.', 'comment');
            ++$totals['skipped'];

            return;
        }
        try {
            $raw = $client->getItemMime($ref);
        } catch (\Throwable $exception) {
            $this->logError(sprintf('Could not load message %s: %s', $ref->id, $exception->getMessage()), $exception);
            ++$totals['errors'];

            return;
        }
        if (null === $raw) {
            $this->logError(sprintf('Message %s has no MIME content. Skipping.', $ref->id));
            ++$totals['errors'];

            return;
        }
        $result = $this->importer->import($connector, $importKey, $raw);
        if ('error' === $result['status']) {
            $this->logError(sprintf('Message %s: %s', $ref->id, (string) $result['reason']));
            ++$totals['errors'];

            return;
        }
        if ('skipped' === $result['status']) {
            $this->log('Already imported. Skipping.', 'comment');
            ++$totals['skipped'];

            return;
        }
        if ($delete) {
            try {
                $client->deleteItem($ref);
                $this->log('Message moved to Deleted Items on the Exchange server.', 'comment');
            } catch (\Throwable $exception) {
                $this->logError(sprintf('Message %s could not be deleted: %s', $ref->id, $exception->getMessage()), $exception);
                ++$totals['errors'];
            }
        }
        $this->log('Message imported successfully.', 'info');
        ++$totals['imported'];
    }

    /**
     * Persists the fetch progress in the stored plugin config: the "X of Y"
     * status in the admin area. Same convention as the POP3 connector.
     *
     * @param array<string, mixed> $config
     */
    private function updateMailsTotal(Connector $connector, array $config, int $serverTotal, bool $delete, int $imported): void
    {
        try {
            $entity = $this->connectorConfigRepository->findOneBy(['connector' => $connector, 'pluginKey' => ExchangeConnectorPlugin::KEY]);
            if (!$entity instanceof ConnectorConfig) {
                return;
            }
            $serverAfter = max(0, $serverTotal - ($delete ? $imported : 0));
            $config['mails_total'] = $delete ? $this->emailRepo->count(['connector' => $connector]) + $serverAfter : $serverTotal;
            $entity->setConfig($config);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logError('Could not update the fetch status: '.$exception->getMessage(), $exception);
        }
    }
}
