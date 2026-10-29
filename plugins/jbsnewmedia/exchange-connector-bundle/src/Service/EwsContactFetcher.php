<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use garethp\ews\API\Type\ContactItemType;
use JBSNewMedia\ExchangeConnectorBundle\Plugin\ExchangeConnectorPlugin;

/**
 * Fetches the contacts of the Exchange address book (Eingang) into the
 * local Address entities (isContact = true). Existing addresses are filled
 * with the fields that are still empty locally; duplicates are skipped.
 * Every contact is linked to the connector's account (account_address) so
 * the EAS backend serves it as ActiveSync contacts folder content.
 */
final class EwsContactFetcher extends AbstractEwsFetcher
{
    public function __construct(
        private readonly EwsClientFactory $clientFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorConfigRepository $connectorConfigRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function fetchContacts(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        $this->bindRequest($request);
        if (!self::isConfigured($config)) {
            $this->log('Exchange configuration incomplete.', 'error');

            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $totals = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        try {
            $this->log('Connecting to the Exchange address book (EWS) ...');
            $client = $this->clientFactory->create($config);
            $this->log('Connection established successfully.');
            $listing = $client->findContacts($request->limit);
            $this->log(sprintf('%d contacts found.', count($listing['items'])));
            $addressRepo = $this->entityManager->getRepository(Address::class);
            foreach ($listing['items'] as $contact) {
                if (!$contact instanceof ContactItemType) {
                    ++$totals['errors'];

                    continue;
                }
                $this->processContact($contact, $connector, $addressRepo, $totals);
            }
            $this->updateTotals($connector, $config, 'contacts_total', $listing['total']);
        } catch (\Throwable $exception) {
            $this->logError('Error during the Exchange contacts sync: '.$exception->getMessage(), $exception);
            ++$totals['errors'];
        }

        return $totals;
    }

    /**
     * @param \Doctrine\Persistence\ObjectRepository<Address> $addressRepo
     * @param array{imported: int, skipped: int, errors: int} $totals
     */
    private function processContact(ContactItemType $contact, Connector $connector, object $addressRepo, array &$totals): void
    {
        $email = self::primaryEmailAddress($contact);
        if (null === $email) {
            $this->log('Contact without e-mail address. Skipping.', 'comment');
            ++$totals['skipped'];

            return;
        }
        $address = $addressRepo->findOneBy(['email' => $email]);
        $needsFlush = false;
        if (!$address instanceof Address) {
            $address = new Address();
            $address->setEmail($email);
            $this->applyContact($address, $contact);
            $this->entityManager->persist($address);
            $needsFlush = true;
            ++$totals['imported'];
            $this->log(sprintf('Contact "%s" imported.', $email), 'info');
        } else {
            $changed = $this->applyContact($address, $contact);
            $needsFlush = $changed;
            ++$totals['skipped'];
            $this->log(sprintf('Contact "%s" already exists%s.', $email, $changed ? ' (fields updated)' : ''), 'comment');
        }
        $needsFlush = $this->linkToAccount($connector, $address) || $needsFlush;
        if ($needsFlush) {
            try {
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                if (!$this->entityManager->isOpen()) {
                    throw $exception;
                }
                $this->logError(sprintf('Contact "%s" could not be saved: %s', $email, $exception->getMessage()), $exception);
                ++$totals['errors'];
            }
        }
    }

    /**
     * Links the address to the connector's account so the EAS backend
     * (ActiveSync contacts folder) serves it. Returns whether a new link
     * was created.
     */
    private function linkToAccount(Connector $connector, Address $address): bool
    {
        $account = $connector->getDefaultAccount();

        if (!$account instanceof Account) {
            $this->log('Connector has no account. Contact is not linked for ActiveSync.', 'comment');

            return false;
        }

        $addressId = $address->getId()?->toRfc4122();

        foreach ($account->getAddressLinks() as $link) {
            if ($link->getAddress()?->getId()?->toRfc4122() === $addressId) {
                return false;
            }
        }

        $account->addAddress($address);

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isConfigured(array $config): bool
    {
        return '' !== self::stringValue($config, 'ewsHost')
            && '' !== self::stringValue($config, 'ewsUsername')
            && '' !== self::stringValue($config, 'ewsPassword');
    }

    /**
     * Applies the contact properties to the Address entity. Only empty
     * fields are filled, existing local data is never overwritten. Returns
     * whether anything was changed.
     */
    private function applyContact(Address $address, ContactItemType $contact): bool
    {
        $changed = $this->fill($address->getName(), self::prop($contact, 'displayName'), $address->setName(...));
        $changed = $this->fill($address->getFirstname(), self::prop($contact, 'givenName'), $address->setFirstname(...)) || $changed;
        $changed = $this->fill($address->getLastname(), self::prop($contact, 'surname'), $address->setLastname(...)) || $changed;
        $changed = $this->fill($address->getCompany(), self::prop($contact, 'companyName'), $address->setCompany(...)) || $changed;
        $phones = self::phoneNumbers($contact);
        $changed = $this->fill($address->getBusinessPhone(), $phones['BusinessPhone'] ?? null, $address->setBusinessPhone(...)) || $changed;
        $changed = $this->fill($address->getMobilePhone(), $phones['MobilePhone'] ?? null, $address->setMobilePhone(...)) || $changed;
        $changed = $this->fill($address->getHomePhone(), $phones['HomePhone'] ?? null, $address->setHomePhone(...)) || $changed;
        if (!$address->isContact()) {
            $address->setIsContact(true);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Writes $newValue when the local value is empty. Returns whether
     * anything was changed.
     */
    private function fill(?string $current, ?string $newValue, \Closure $setter): bool
    {
        if (null === $newValue || '' === $newValue || null !== $current) {
            return false;
        }
        $setter($newValue);

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function updateTotals(Connector $connector, array $config, string $key, int $total): void
    {
        try {
            $entity = $this->connectorConfigRepository->findOneBy(['connector' => $connector, 'pluginKey' => ExchangeConnectorPlugin::KEY]);
            if (!$entity instanceof ConnectorConfig) {
                return;
            }
            $config[$key] = $total;
            $entity->setConfig($config);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logError('Could not update the fetch status: '.$exception->getMessage(), $exception);
        }
    }

    private static function prop(ContactItemType $contact, string $getter): ?string
    {
        $method = 'get'.ucfirst($getter);
        if (!method_exists($contact, $method)) {
            return null;
        }
        $value = $contact->{$method}();
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' !== $value ? $value : null;
    }

    private static function getDictionary(ContactItemType $contact, string $getter): mixed
    {
        $method = 'get'.ucfirst($getter);
        if (!method_exists($contact, $method)) {
            return null;
        }

        return $contact->{$method}();
    }

    /**
     * Resolves the primary e-mail address of a contact (EmailAddress1,
     * fallback to any other address entry).
     */
    public static function primaryEmailAddress(ContactItemType $contact): ?string
    {
        $first = null;
        foreach (self::dictionaryEntries(self::getDictionary($contact, 'emailAddresses')) as $entry) {
            $key = self::entryKey($entry);
            $value = EwsTypeHelper::entryValue($entry);
            if (null === $value) {
                continue;
            }
            if ('EmailAddress1' === $key) {
                return $value;
            }
            $first ??= $value;
        }

        return $first;
    }

    /**
     * @return array<string, string>
     */
    public static function phoneNumbers(ContactItemType $contact): array
    {
        $phones = [];
        foreach (self::dictionaryEntries(self::getDictionary($contact, 'phoneNumbers')) as $entry) {
            $key = self::entryKey($entry);
            $value = EwsTypeHelper::entryValue($entry);
            if ('' === $key || null === $value) {
                continue;
            }
            $phones[$key] = $value;
        }

        return $phones;
    }

    private static function entryKey(object $entry): string
    {
        if (!method_exists($entry, 'getKey')) {
            return '';
        }
        $key = $entry->getKey();

        return is_string($key) ? $key : '';
    }

    /**
     * Normalizes the EWS dictionary wrapper ("<EmailAddresses><Entry …/>")
     * into a flat list of entry objects.
     *
     * @return list<object>
     */
    public static function dictionaryEntries(mixed $dictionary): array
    {
        if (null === $dictionary) {
            return [];
        }
        if (is_object($dictionary)) {
            $vars = (array) $dictionary;
            $dictionary = $vars['Entry'] ?? $vars;
        }
        if (!is_array($dictionary)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $entry): object => (object) $entry, $dictionary));
    }
}
