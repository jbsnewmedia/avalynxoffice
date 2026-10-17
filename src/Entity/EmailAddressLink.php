<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Repository\EmailAddressLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: EmailAddressLinkRepository::class)]
#[ORM\Table(name: 'email_address_link')]
#[ORM\HasLifecycleCallbacks]
class EmailAddressLink
{
    use TimestampableTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Email $email = null;
    #[ORM\ManyToOne(inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Address $address = null;
    #[ORM\Column(type: 'string', enumType: EmailAddressType::class)]
    private EmailAddressType $type;

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

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getType(): EmailAddressType
    {
        return $this->type;
    }

    public function setType(EmailAddressType $type): self
    {
        $this->type = $type;

        return $this;
    }
}
