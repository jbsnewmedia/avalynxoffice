<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\UserLoginSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One concurrent login of a user: created on every interactive login,
 * revoked when the user ends the session from their profile (or on logout).
 * The session token is stored inside the Symfony session and links the live
 * session to this row.
 */
#[ORM\Entity(repositoryClass: UserLoginSessionRepository::class)]
#[ORM\Table(name: 'app_user_login_session')]
#[ORM\HasLifecycleCallbacks]
class UserLoginSession
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'loginSessions')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;
    #[ORM\Column(name: 'session_token', type: Types::STRING, length: 64, unique: true)]
    private string $sessionToken = '';
    #[ORM\Column(name: 'remember_me', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $rememberMe = false;
    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;
    #[ORM\Column(name: 'user_agent', type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;
    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    private ?string $browser = null;
    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    private ?string $os = null;
    #[ORM\Column(name: 'geo_city', type: Types::STRING, length: 100, nullable: true)]
    private ?string $geoCity = null;
    #[ORM\Column(name: 'geo_country', type: Types::STRING, length: 100, nullable: true)]
    private ?string $geoCountry = null;
    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;
    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

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

    public function getSessionToken(): string
    {
        return $this->sessionToken;
    }

    public function setSessionToken(string $sessionToken): self
    {
        $this->sessionToken = $sessionToken;

        return $this;
    }

    public function isRememberMe(): bool
    {
        return $this->rememberMe;
    }

    public function setRememberMe(bool $rememberMe): self
    {
        $this->rememberMe = $rememberMe;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getBrowser(): ?string
    {
        return $this->browser;
    }

    public function setBrowser(?string $browser): self
    {
        $this->browser = $browser;

        return $this;
    }

    public function getOs(): ?string
    {
        return $this->os;
    }

    public function setOs(?string $os): self
    {
        $this->os = $os;

        return $this;
    }

    public function getGeoCity(): ?string
    {
        return $this->geoCity;
    }

    public function setGeoCity(?string $geoCity): self
    {
        $this->geoCity = $geoCity;

        return $this;
    }

    public function getGeoCountry(): ?string
    {
        return $this->geoCountry;
    }

    public function setGeoCountry(?string $geoCountry): self
    {
        $this->geoCountry = $geoCountry;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }
}
