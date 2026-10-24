<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAddress;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Contact CRUD for the web UI. Device synchronization is provided by the
 * separate avalynxofficeeas project.
 */
final readonly class ContactService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    /**
     * @return Address[]
     */
    public function contactsForAccount(Account $account): array
    {
        $contacts = [];

        foreach ($account->getAddressLinks() as $link) {
            $address = $link->getAddress();

            if (null !== $address && $address->isContact()) {
                $contacts[] = $address;
            }
        }

        usort($contacts, static fn (Address $left, Address $right): int => strcasecmp($left->displayName(), $right->displayName()));

        return $contacts;
    }

    public function contactForAccount(Account $account, string $addressId): ?Address
    {
        foreach ($this->contactsForAccount($account) as $address) {
            if ($address->getId()?->toRfc4122() === strtolower($addressId)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Creates a contact from UI form data. The form has already applied the
     * submitted values to the given address.
     */
    public function createFromForm(Account $account, Address $address): Address
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::CONTACT_WRITE);
        $address->setIsContact(true);
        $account->addAddress($address);

        $this->entityManager->persist($address);
        $this->entityManager->flush();

        return $address;
    }

    /**
     * Persists changes made to a contact; the form has already applied the
     * submitted values.
     */
    public function save(Address $address): void
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::CONTACT_WRITE);
        $this->entityManager->flush();
    }

    public function delete(Address $address): void
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::CONTACT_WRITE);
        // The account links are not cascade-removed with the address; load
        // them explicitly so the rows and the in-memory state stay in sync.
        foreach ($this->entityManager->getRepository(AccountAddress::class)->findBy(['address' => $address]) as $link) {
            $link->getAccount()?->getAddressLinks()->removeElement($link);
            $this->entityManager->remove($link);
        }

        $this->entityManager->remove($address);
        $this->entityManager->flush();
    }
}
