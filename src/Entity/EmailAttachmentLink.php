<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\EmailAttachmentLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: EmailAttachmentLinkRepository::class)]
#[ORM\Table(name: 'email_attachment_link')]
#[ORM\HasLifecycleCallbacks]
class EmailAttachmentLink
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }
    #[ORM\ManyToOne(inversedBy: 'emailAttachments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Email $email = null;
    #[ORM\ManyToOne(inversedBy: 'emailAttachments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Attachment $attachment = null;

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    public function setEmail(?Email $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getAttachment(): ?Attachment
    {
        return $this->attachment;
    }

    public function setAttachment(?Attachment $attachment): self
    {
        $this->attachment = $attachment;

        return $this;
    }
}
