<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mailbox_address')]
#[ORM\UniqueConstraint(name: 'uniq_mailbox_address', columns: ['mailbox_id', 'address_id'])]
#[ORM\HasLifecycleCallbacks]
class MailboxAddress
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Mailbox::class, inversedBy: 'addressLinks')]
    #[ORM\JoinColumn(name: 'mailbox_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Mailbox $mailbox = null;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[ORM\JoinColumn(name: 'address_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Address $address = null;

    public function getMailbox(): ?Mailbox
    {
        return $this->mailbox;
    }

    public function setMailbox(?Mailbox $mailbox): self
    {
        $this->mailbox = $mailbox;

        return $this;
    }

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): self
    {
        $this->address = $address;

        return $this;
    }
}
