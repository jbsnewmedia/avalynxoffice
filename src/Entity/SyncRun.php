<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\SyncRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One executed pass of the sync service (mails, contacts, calendar). The
 * latest pass is shown as the system status in the admin area.
 */
#[ORM\Entity(repositoryClass: SyncRunRepository::class)]
#[ORM\Table(name: 'sync_run')]
class SyncRun
{
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_ERROR = 'error';

    public const string TRIGGER_DAEMON = 'daemon';
    public const string TRIGGER_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $trigger = self::TRIGGER_MANUAL;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $mailsImported = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $mailsSkipped = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $mailsErrors = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $contactsCount = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $calendarCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
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

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function setTrigger(string $trigger): self
    {
        $this->trigger = $trigger;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getMailsImported(): int
    {
        return $this->mailsImported;
    }

    public function setMailsImported(int $mailsImported): self
    {
        $this->mailsImported = $mailsImported;

        return $this;
    }

    public function getMailsSkipped(): int
    {
        return $this->mailsSkipped;
    }

    public function setMailsSkipped(int $mailsSkipped): self
    {
        $this->mailsSkipped = $mailsSkipped;

        return $this;
    }

    public function getMailsErrors(): int
    {
        return $this->mailsErrors;
    }

    public function setMailsErrors(int $mailsErrors): self
    {
        $this->mailsErrors = $mailsErrors;

        return $this;
    }

    public function getContactsCount(): int
    {
        return $this->contactsCount;
    }

    public function setContactsCount(int $contactsCount): self
    {
        $this->contactsCount = $contactsCount;

        return $this;
    }

    public function getCalendarCount(): int
    {
        return $this->calendarCount;
    }

    public function setCalendarCount(int $calendarCount): self
    {
        $this->calendarCount = $calendarCount;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }
}
