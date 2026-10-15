<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_mailbox')]
#[ORM\UniqueConstraint(name: 'uniq_user_mailbox', columns: ['user_id', 'mailbox_id'])]
#[ORM\HasLifecycleCallbacks]
class UserMailbox
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'mailboxLinks')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private ?User $user = null;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Mailbox::class, inversedBy: 'userLinks')]
    #[ORM\JoinColumn(name: 'mailbox_id', referencedColumnName: 'id')]
    private ?Mailbox $mailbox = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getMailbox(): ?Mailbox
    {
        return $this->mailbox;
    }

    public function setMailbox(?Mailbox $mailbox): self
    {
        $this->mailbox = $mailbox;

        return $this;
    }
}
