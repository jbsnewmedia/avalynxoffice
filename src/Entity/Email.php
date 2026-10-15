<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: EmailRepository::class)]
#[ORM\Table(name: 'email')]
#[ORM\HasLifecycleCallbacks]
class Email
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(name: 'content_hash', type: 'string', length: 64, unique: true, nullable: true)]
    private ?string $contentHash = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $subject = null;
    #[ORM\Column(name: 'preview_body', type: 'text', nullable: true)]
    private ?string $previewBody = null;
    #[ORM\Column(name: 'received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;
    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;
    #[ORM\Column(name: 'is_outgoing', type: 'boolean')]
    private bool $isOutgoing = false;
    #[ORM\Column(name: 'is_read', type: 'boolean')]
    private bool $isRead = false;
    #[ORM\Column(name: 'is_favorite', type: 'boolean', options: ['default' => false])]
    private bool $isFavorite = false;
    #[ORM\Column(name: 'is_important', type: 'boolean', options: ['default' => false])]
    private bool $isImportant = false;
    #[ORM\Column(name: 'is_draft', type: 'boolean', options: ['default' => false])]
    private bool $isDraft = false;
    #[ORM\Column(name: 'is_sending', type: 'boolean', options: ['default' => false])]
    private bool $isSending = false;
    #[ORM\Column(name: 'is_sent', type: 'boolean', options: ['default' => false])]
    private bool $isSent = false;
    #[ORM\Column(name: 'license_hold', type: 'boolean', options: ['default' => false])]
    private bool $licenseHold = false;
    #[ORM\Column(name: 'has_encoding_issues', type: 'boolean', options: ['default' => false])]
    private bool $hasEncodingIssues = false;
    #[ORM\Column(name: 'scheduled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;
    #[ORM\ManyToOne(targetEntity: Mailbox::class)]
    #[ORM\JoinColumn(name: 'mailbox_id', referencedColumnName: 'id', nullable: true)]
    private ?Mailbox $mailbox = null;
    #[ORM\Column(name: 'message_id', type: 'string', length: 255, nullable: true)]
    private ?string $messageId = null;
    #[ORM\Column(name: 'import_key', type: 'string', length: 190, unique: true, nullable: true)]
    private ?string $importKey = null;
    /**
     * @var Collection<int, EmailFolder>
     */
    #[ORM\OneToMany(mappedBy: 'email', targetEntity: EmailFolder::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $emailFolders;
    /**
     * @var Collection<int, EmailAddressLink>
     */
    #[ORM\OneToMany(mappedBy: 'email', targetEntity: EmailAddressLink::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $emailAddresses;
    /**
     * @var Collection<int, EmailBodyLink>
     */
    #[ORM\OneToMany(mappedBy: 'email', targetEntity: EmailBodyLink::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $emailBodies;
    /**
     * @var Collection<int, EmailAttachmentLink>
     */
    #[ORM\OneToMany(mappedBy: 'email', targetEntity: EmailAttachmentLink::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $emailAttachments;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailFolders = new ArrayCollection();
        $this->emailAddresses = new ArrayCollection();
        $this->emailBodies = new ArrayCollection();
        $this->emailAttachments = new ArrayCollection();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function setId(UuidV7 $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): self
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getPreviewBody(): ?string
    {
        return $this->previewBody;
    }

    public function setPreviewBody(?string $previewBody): self
    {
        $this->previewBody = $previewBody;

        return $this;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(?\DateTimeImmutable $receivedAt): self
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function isOutgoing(): bool
    {
        return $this->isOutgoing;
    }

    public function setIsOutgoing(bool $outgoing): self
    {
        $this->isOutgoing = $outgoing;

        return $this;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $read): self
    {
        $this->isRead = $read;

        return $this;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(bool $favorite): self
    {
        $this->isFavorite = $favorite;

        return $this;
    }

    public function isImportant(): bool
    {
        return $this->isImportant;
    }

    public function setIsImportant(bool $important): self
    {
        $this->isImportant = $important;

        return $this;
    }

    public function isDraft(): bool
    {
        return $this->isDraft;
    }

    public function setIsDraft(bool $draft): self
    {
        $this->isDraft = $draft;

        return $this;
    }

    public function isSending(): bool
    {
        return $this->isSending;
    }

    public function setIsSending(bool $sending): self
    {
        $this->isSending = $sending;

        return $this;
    }

    public function isSent(): bool
    {
        return $this->isSent;
    }

    public function setIsSent(bool $sent): self
    {
        $this->isSent = $sent;

        return $this;
    }

    /**
     * Explicit status for outgoing messages paused because of the license
     * policy. Held messages are preserved and never sent automatically;
     * they are released with "app:license:release-mail".
     */
    public function isLicenseHold(): bool
    {
        return $this->licenseHold;
    }

    public function setLicenseHold(bool $licenseHold): self
    {
        $this->licenseHold = $licenseHold;

        return $this;
    }

    public function hasEncodingIssues(): bool
    {
        return $this->hasEncodingIssues;
    }

    public function setHasEncodingIssues(bool $hasIssues): self
    {
        $this->hasEncodingIssues = $hasIssues;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;

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

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getImportKey(): ?string
    {
        return $this->importKey;
    }

    public function setImportKey(?string $importKey): self
    {
        $this->importKey = $importKey;

        return $this;
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    public function getEmailFolders(): Collection
    {
        return $this->emailFolders;
    }

    public function addEmailFolder(EmailFolder $emailFolder): self
    {
        if (!$this->emailFolders->contains($emailFolder)) {
            $this->emailFolders->add($emailFolder);
            $emailFolder->setEmail($this);
        }

        return $this;
    }

    public function removeEmailFolder(EmailFolder $emailFolder): self
    {
        if ($this->emailFolders->removeElement($emailFolder)) {
            if ($emailFolder->getEmail() === $this) {
                $emailFolder->setEmail(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailAddressLink>
     */
    public function getEmailAddresses(): Collection
    {
        return $this->emailAddresses;
    }

    public function addEmailAddress(EmailAddressLink $link): self
    {
        if (!$this->emailAddresses->contains($link)) {
            $this->emailAddresses->add($link);
            $link->setEmail($this);
        }

        return $this;
    }

    public function removeEmailAddress(EmailAddressLink $link): self
    {
        if ($this->emailAddresses->removeElement($link)) {
            if ($link->getEmail() === $this) {
                $link->setEmail(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailBodyLink>
     */
    public function getEmailBodies(): Collection
    {
        return $this->emailBodies;
    }

    public function addEmailBody(EmailBodyLink $link): self
    {
        if (!$this->emailBodies->contains($link)) {
            $this->emailBodies->add($link);
            $link->setEmail($this);
        }

        return $this;
    }

    public function removeEmailBody(EmailBodyLink $link): self
    {
        if ($this->emailBodies->removeElement($link)) {
            if ($link->getEmail() === $this) {
                $link->setEmail(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailAttachmentLink>
     */
    public function getEmailAttachments(): Collection
    {
        return $this->emailAttachments;
    }

    public function addEmailAttachment(EmailAttachmentLink $link): self
    {
        if (!$this->emailAttachments->contains($link)) {
            $this->emailAttachments->add($link);
            $link->setEmail($this);
        }

        return $this;
    }

    public function removeEmailAttachment(EmailAttachmentLink $link): self
    {
        if ($this->emailAttachments->removeElement($link)) {
            if ($link->getEmail() === $this) {
                $link->setEmail(null);
            }
        }

        return $this;
    }
}
