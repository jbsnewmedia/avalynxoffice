<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\RecoveryAddressRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Recovery-only email address of a user account. Recovery addresses are never
 * used for regular mail retrieval or sending; they exclusively receive
 * account-recovery messages (password reset codes) via the system mailer.
 * A user may register at most two of them.
 *
 * A freshly registered address is unverified: the owner has to confirm it
 * via a link mailed to the address (double opt-in) before it takes part in
 * any recovery flow. Only the SHA-256 hash of the verification token is
 * stored.
 */
#[ORM\Entity(repositoryClass: RecoveryAddressRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_recovery_address_user_email', columns: ['user_id', 'email'])]
#[ORM\Table(name: 'app_recovery_address')]
#[ORM\HasLifecycleCallbacks]
class RecoveryAddress
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'recoveryAddresses')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;
    #[ORM\Column(type: 'string', length: 255)]
    /** @var non-empty-string */
    private string $email;
    #[ORM\Column(name: 'verified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;
    #[ORM\Column(name: 'verification_token_hash', type: 'string', length: 64, nullable: true)]
    private ?string $verificationTokenHash = null;
    #[ORM\Column(name: 'verification_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verificationExpiresAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return non-empty-string
     */
    public function getEmail(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Email must not be empty');
        }

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

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function isVerified(): bool
    {
        return null !== $this->verifiedAt;
    }

    public function getVerificationTokenHash(): ?string
    {
        return $this->verificationTokenHash;
    }

    public function setVerificationTokenHash(?string $verificationTokenHash): self
    {
        $this->verificationTokenHash = $verificationTokenHash;

        return $this;
    }

    public function getVerificationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->verificationExpiresAt;
    }

    public function setVerificationExpiresAt(?\DateTimeImmutable $verificationExpiresAt): self
    {
        $this->verificationExpiresAt = $verificationExpiresAt;

        return $this;
    }

    /**
     * Drops the pending verification token (after a successful confirmation
     * or when a new token is issued).
     */
    public function clearVerification(): self
    {
        $this->verificationTokenHash = null;
        $this->verificationExpiresAt = null;

        return $this;
    }
}
