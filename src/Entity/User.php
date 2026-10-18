<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    /** @var non-empty-string */
    private string $email;
    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];
    #[ORM\Column(type: 'string')]
    private string $password;
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;
    /**
     * Last successful authentication (web login or device sync). Part of the
     * Active Seat definition (APL 1.0): only accounts that authenticated
     * within the last 30 calendar days consume a license seat.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAuthAt = null;
    /**
     * @var Collection<int, UserMailbox>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserMailbox::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $mailboxLinks;
    /**
     * Recovery-only email addresses (maximum two). They never take part in
     * mail retrieval or sending and exclusively receive account-recovery
     * messages.
     *
     * @var Collection<int, RecoveryAddress>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: RecoveryAddress::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $recoveryAddresses;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->mailboxLinks = new ArrayCollection();
        $this->recoveryAddresses = new ArrayCollection();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @param non-empty-string $email
     */
    public function setEmail(string $email): self
    {
        $this->email = strtolower($email);

        return $this;
    }

    /**
     * @return non-empty-string
     */
    #[\Override]
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Email must not be empty');
        }

        return $this->email;
    }

    #[\Override]
    public function getRoles(): array
    {
        $roles = $this->roles;
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param array<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    #[\Override]
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Whether the account is activated. An activated account is a necessary
     * condition of the Active Seat definition; deactivated accounts never
     * consume a seat.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getLastAuthAt(): ?\DateTimeImmutable
    {
        return $this->lastAuthAt;
    }

    public function setLastAuthAt(?\DateTimeImmutable $lastAuthAt): self
    {
        $this->lastAuthAt = $lastAuthAt;

        return $this;
    }

    /**
     * @return Collection<int, UserMailbox>
     */
    public function getMailboxLinks(): Collection
    {
        return $this->mailboxLinks;
    }

    /**
     * @return Collection<int, Mailbox>
     */
    public function getMailboxes(): Collection
    {
        /** @var ArrayCollection<int, Mailbox> $mailboxes */
        $mailboxes = new ArrayCollection();
        foreach ($this->mailboxLinks as $link) {
            $mb = $link->getMailbox();
            if ($mb && !$mailboxes->contains($mb)) {
                $mailboxes->add($mb);
            }
        }

        return $mailboxes;
    }

    public function addMailbox(Mailbox $mailbox): self
    {
        foreach ($this->mailboxLinks as $link) {
            if ($link->getMailbox() === $mailbox) {
                return $this;
            }
        }
        $link = new UserMailbox();
        $link->setUser($this);
        $link->setMailbox($mailbox);
        $this->mailboxLinks->add($link);

        return $this;
    }

    public function removeMailbox(Mailbox $mailbox): self
    {
        foreach ($this->mailboxLinks as $link) {
            if ($link->getMailbox() === $mailbox) {
                $this->mailboxLinks->removeElement($link);
                break;
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RecoveryAddress>
     */
    public function getRecoveryAddresses(): Collection
    {
        return $this->recoveryAddresses;
    }

    public function addRecoveryAddress(RecoveryAddress $recoveryAddress): self
    {
        if (!$this->recoveryAddresses->contains($recoveryAddress)) {
            $this->recoveryAddresses->add($recoveryAddress);
            $recoveryAddress->setUser($this);
        }

        return $this;
    }

    public function removeRecoveryAddress(RecoveryAddress $recoveryAddress): self
    {
        if ($this->recoveryAddresses->contains($recoveryAddress)) {
            $this->recoveryAddresses->removeElement($recoveryAddress);
            if ($recoveryAddress->getUser() === $this) {
                $recoveryAddress->setUser(null);
            }
        }

        return $this;
    }
}
