<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\CalendarEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
#[ORM\Table(name: 'calendar_event')]
#[ORM\Index(name: 'idx_calendar_event_uid', columns: ['uid'])]
#[ORM\HasLifecycleCallbacks]
class CalendarEvent
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $title;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $location = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startAt;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endAt = null;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isAllDay = false;
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $timezone = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recurrenceType = null;
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $recurrenceInterval = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $recurrenceUntil = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recurrenceDayOfWeek = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recurrenceDayOfMonth = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recurrenceWeekOfMonth = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recurrenceMonthOfYear = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $uid = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dtstamp = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $busyStatus = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $sensitivity = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $reminder = null;
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: true)]
    private ?Account $account = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): self
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeImmutable $endAt): self
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->isAllDay;
    }

    public function setIsAllDay(bool $isAllDay): self
    {
        $this->isAllDay = $isAllDay;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * ActiveSync recurrence type (0 = daily .. 5 = yearly on the Nth day) or
     * null when the event does not recur.
     */
    public function getRecurrenceType(): ?int
    {
        return $this->recurrenceType;
    }

    public function setRecurrenceType(?int $recurrenceType): self
    {
        $this->recurrenceType = $recurrenceType;

        return $this;
    }

    public function getRecurrenceInterval(): ?int
    {
        return $this->recurrenceInterval;
    }

    public function setRecurrenceInterval(?int $recurrenceInterval): self
    {
        $this->recurrenceInterval = $recurrenceInterval;

        return $this;
    }

    public function getRecurrenceUntil(): ?\DateTimeImmutable
    {
        return $this->recurrenceUntil;
    }

    public function setRecurrenceUntil(?\DateTimeImmutable $recurrenceUntil): self
    {
        $this->recurrenceUntil = $recurrenceUntil;

        return $this;
    }

    public function getRecurrenceDayOfWeek(): ?int
    {
        return $this->recurrenceDayOfWeek;
    }

    public function setRecurrenceDayOfWeek(?int $recurrenceDayOfWeek): self
    {
        $this->recurrenceDayOfWeek = $recurrenceDayOfWeek;

        return $this;
    }

    public function getRecurrenceDayOfMonth(): ?int
    {
        return $this->recurrenceDayOfMonth;
    }

    public function setRecurrenceDayOfMonth(?int $recurrenceDayOfMonth): self
    {
        $this->recurrenceDayOfMonth = $recurrenceDayOfMonth;

        return $this;
    }

    public function getRecurrenceWeekOfMonth(): ?int
    {
        return $this->recurrenceWeekOfMonth;
    }

    public function setRecurrenceWeekOfMonth(?int $recurrenceWeekOfMonth): self
    {
        $this->recurrenceWeekOfMonth = $recurrenceWeekOfMonth;

        return $this;
    }

    public function getRecurrenceMonthOfYear(): ?int
    {
        return $this->recurrenceMonthOfYear;
    }

    public function setRecurrenceMonthOfYear(?int $recurrenceMonthOfYear): self
    {
        $this->recurrenceMonthOfYear = $recurrenceMonthOfYear;

        return $this;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function getDtstamp(): ?\DateTimeImmutable
    {
        return $this->dtstamp;
    }

    public function setDtstamp(?\DateTimeImmutable $dtstamp): self
    {
        $this->dtstamp = $dtstamp;

        return $this;
    }

    public function getBusyStatus(): ?int
    {
        return $this->busyStatus;
    }

    public function setBusyStatus(?int $busyStatus): self
    {
        $this->busyStatus = $busyStatus;

        return $this;
    }

    public function getSensitivity(): ?int
    {
        return $this->sensitivity;
    }

    public function setSensitivity(?int $sensitivity): self
    {
        $this->sensitivity = $sensitivity;

        return $this;
    }

    public function getReminder(): ?int
    {
        return $this->reminder;
    }

    public function setReminder(?int $reminder): self
    {
        $this->reminder = $reminder;

        return $this;
    }
}
