<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\PasswordResetRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Administrator-assisted password reset request for a user who cannot access
 * the account (no mail access). The request carries two secrets: a token
 * shown once to the user and a 4-digit release number shown once to the
 * administrator. Both are stored hashed; only the requesting administrator
 * and the released-by administrator (for the audit trail) are kept as IDs.
 *
 * Status flow: pending -> claimed (user submitted the token) -> released
 * (admin confirmed the number) -> completed. Alternative terminal states:
 * expired (time window passed) and invalidated (too many wrong number
 * attempts or superseded by a newer request).
 */
#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
#[ORM\Table(name: 'app_password_reset_request')]
#[ORM\HasLifecycleCallbacks]
class PasswordResetRequest
{
    use TimestampableTrait;

    /** The request must be claimed within this window. */
    public const int CLAIM_TTL_SECONDS = 600;

    /** After the release the password must be set within this window. */
    public const int RELEASE_TTL_SECONDS = 180;

    /** Maximum attempts to enter the correct release number. */
    public const int MAX_NUMBER_ATTEMPTS = 3;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_INVALIDATED = 'invalidated';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;
    /** sha256 hash of the token shown to the user. */
    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
    private string $tokenHash = '';
    /** sha256 hash of the 4-digit release number. */
    #[ORM\Column(name: 'release_number_hash', type: 'string', length: 64)]
    private string $releaseNumberHash = '';
    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;
    /** Absolute deadline for the user to show the token (claim step). */
    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $expiresAt = null;
    /** Absolute deadline for setting the new password after release. */
    #[ORM\Column(name: 'release_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releaseExpiresAt = null;
    #[ORM\Column(name: 'attempts', type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;
    /** Admin who started the request (audit trail). */
    #[ORM\Column(name: 'created_by_admin', type: 'uuid', nullable: true)]
    private ?UuidV7 $createdByAdmin = null;
    /** Admin who released the request (audit trail). */
    #[ORM\Column(name: 'released_by_admin', type: 'uuid', nullable: true)]
    private ?UuidV7 $releasedByAdmin = null;
    #[ORM\Column(name: 'created_ip', type: 'string', length: 45, nullable: true)]
    private ?string $createdIp = null;
    #[ORM\Column(name: 'released_ip', type: 'string', length: 45, nullable: true)]
    private ?string $releasedIp = null;
    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getReleaseNumberHash(): string
    {
        return $this->releaseNumberHash;
    }

    public function setReleaseNumberHash(string $releaseNumberHash): self
    {
        $this->releaseNumberHash = $releaseNumberHash;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getReleaseExpiresAt(): ?\DateTimeImmutable
    {
        return $this->releaseExpiresAt;
    }

    public function setReleaseExpiresAt(?\DateTimeImmutable $releaseExpiresAt): self
    {
        $this->releaseExpiresAt = $releaseExpiresAt;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): self
    {
        ++$this->attempts;

        return $this;
    }

    public function getCreatedByAdmin(): ?UuidV7
    {
        return $this->createdByAdmin;
    }

    public function setCreatedByAdmin(?UuidV7 $createdByAdmin): self
    {
        $this->createdByAdmin = $createdByAdmin;

        return $this;
    }

    public function getReleasedByAdmin(): ?UuidV7
    {
        return $this->releasedByAdmin;
    }

    public function setReleasedByAdmin(?UuidV7 $releasedByAdmin): self
    {
        $this->releasedByAdmin = $releasedByAdmin;

        return $this;
    }

    public function getCreatedIp(): ?string
    {
        return $this->createdIp;
    }

    public function setCreatedIp(?string $createdIp): self
    {
        $this->createdIp = $createdIp;

        return $this;
    }

    public function getReleasedIp(): ?string
    {
        return $this->releasedIp;
    }

    public function setReleasedIp(?string $releasedIp): self
    {
        $this->releasedIp = $releasedIp;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }
}
