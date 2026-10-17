<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\BodyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: BodyRepository::class)]
#[ORM\Table(name: 'body')]
#[ORM\HasLifecycleCallbacks]
class Body
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'text')]
    private string $content;
    /**
     * @var Collection<int, EmailBodyLink>
     */
    #[ORM\OneToMany(mappedBy: 'body', targetEntity: EmailBodyLink::class, orphanRemoval: true)]
    private Collection $emailBodies;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailBodies = new ArrayCollection();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @return Collection<int, EmailBodyLink>
     */
    public function getEmailBodies(): Collection
    {
        return $this->emailBodies;
    }
}
