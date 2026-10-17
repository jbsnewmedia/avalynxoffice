<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxAddress;
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
    public function contactsForMailbox(Mailbox $mailbox): array
    {
        $contacts = [];

        foreach ($mailbox->getAddressLinks() as $link) {
            $address = $link->getAddress();

            if (null !== $address && $address->isContact()) {
                $contacts[] = $address;
            }
        }

        usort($contacts, static fn (Address $left, Address $right): int => strcasecmp($left->displayName(), $right->displayName()));

        return $contacts;
    }

    public function contactForMailbox(Mailbox $mailbox, string $addressId): ?Address
    {
        foreach ($this->contactsForMailbox($mailbox) as $address) {
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
    public function createFromForm(Mailbox $mailbox, Address $address): Address
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::CONTACT_WRITE);
        $address->setIsContact(true);
        $mailbox->addAddress($address);

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
        // The mailbox links are not cascade-removed with the address; load
        // them explicitly so the rows and the in-memory state stay in sync.
        foreach ($this->entityManager->getRepository(MailboxAddress::class)->findBy(['address' => $address]) as $link) {
            $link->getMailbox()?->getAddressLinks()->removeElement($link);
            $this->entityManager->remove($link);
        }

        $this->entityManager->remove($address);
        $this->entityManager->flush();
    }
}
