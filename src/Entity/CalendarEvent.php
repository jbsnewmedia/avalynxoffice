<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\CalendarEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $organizerName = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $organizerEmail = null;
    /**
     * @var list<array{name: ?string, email: ?string, type: ?int, status: ?int}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $attendees = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $meetingStatus = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $responseType = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $responseRequested = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $disallowNewTimeProposal = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $onlineMeetingConfLink = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $onlineMeetingExternalLink = null;
    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $categories = null;
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $recurrenceOccurrences = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recurrenceCalendarType = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recurrenceFirstDayOfWeek = null;
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: true)]
    private ?Account $account = null;
    /**
     * @var Collection<int, CalendarEventException>
     */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: CalendarEventException::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $exceptions;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->exceptions = new ArrayCollection();
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

    public function getOrganizerName(): ?string
    {
        return $this->organizerName;
    }

    public function setOrganizerName(?string $organizerName): self
    {
        $this->organizerName = $organizerName;

        return $this;
    }

    public function getOrganizerEmail(): ?string
    {
        return $this->organizerEmail;
    }

    public function setOrganizerEmail(?string $organizerEmail): self
    {
        $this->organizerEmail = $organizerEmail;

        return $this;
    }

    /**
     * @return list<array{name: ?string, email: ?string, type: ?int, status: ?int}>|null
     */
    public function getAttendees(): ?array
    {
        return $this->attendees;
    }

    /**
     * @param list<array{name: ?string, email: ?string, type: ?int, status: ?int}>|null $attendees
     */
    public function setAttendees(?array $attendees): self
    {
        $this->attendees = $attendees;

        return $this;
    }

    public function getMeetingStatus(): ?int
    {
        return $this->meetingStatus;
    }

    public function setMeetingStatus(?int $meetingStatus): self
    {
        $this->meetingStatus = $meetingStatus;

        return $this;
    }

    public function getResponseType(): ?int
    {
        return $this->responseType;
    }

    public function setResponseType(?int $responseType): self
    {
        $this->responseType = $responseType;

        return $this;
    }

    public function getResponseRequested(): ?bool
    {
        return $this->responseRequested;
    }

    public function setResponseRequested(?bool $responseRequested): self
    {
        $this->responseRequested = $responseRequested;

        return $this;
    }

    public function getDisallowNewTimeProposal(): ?bool
    {
        return $this->disallowNewTimeProposal;
    }

    public function setDisallowNewTimeProposal(?bool $disallowNewTimeProposal): self
    {
        $this->disallowNewTimeProposal = $disallowNewTimeProposal;

        return $this;
    }

    public function getOnlineMeetingConfLink(): ?string
    {
        return $this->onlineMeetingConfLink;
    }

    public function setOnlineMeetingConfLink(?string $onlineMeetingConfLink): self
    {
        $this->onlineMeetingConfLink = $onlineMeetingConfLink;

        return $this;
    }

    public function getOnlineMeetingExternalLink(): ?string
    {
        return $this->onlineMeetingExternalLink;
    }

    public function setOnlineMeetingExternalLink(?string $onlineMeetingExternalLink): self
    {
        $this->onlineMeetingExternalLink = $onlineMeetingExternalLink;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getCategories(): ?array
    {
        return $this->categories;
    }

    /**
     * @param list<string>|null $categories
     */
    public function setCategories(?array $categories): self
    {
        $this->categories = $categories;

        return $this;
    }

    public function getRecurrenceOccurrences(): ?int
    {
        return $this->recurrenceOccurrences;
    }

    public function setRecurrenceOccurrences(?int $recurrenceOccurrences): self
    {
        $this->recurrenceOccurrences = $recurrenceOccurrences;

        return $this;
    }

    public function getRecurrenceCalendarType(): ?int
    {
        return $this->recurrenceCalendarType;
    }

    public function setRecurrenceCalendarType(?int $recurrenceCalendarType): self
    {
        $this->recurrenceCalendarType = $recurrenceCalendarType;

        return $this;
    }

    public function getRecurrenceFirstDayOfWeek(): ?int
    {
        return $this->recurrenceFirstDayOfWeek;
    }

    public function setRecurrenceFirstDayOfWeek(?int $recurrenceFirstDayOfWeek): self
    {
        $this->recurrenceFirstDayOfWeek = $recurrenceFirstDayOfWeek;

        return $this;
    }

    /**
     * @return Collection<int, CalendarEventException>
     */
    public function getExceptions(): Collection
    {
        return $this->exceptions;
    }

    public function addException(CalendarEventException $exception): self
    {
        if (!$this->exceptions->contains($exception)) {
            $this->exceptions->add($exception);
            $exception->setEvent($this);
        }

        return $this;
    }

    public function removeException(CalendarEventException $exception): self
    {
        if ($this->exceptions->removeElement($exception)) {
            if ($exception->getEvent() === $this) {
                $exception->setEvent(null);
            }
        }

        return $this;
    }
}
