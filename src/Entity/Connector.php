<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\ConnectorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A connector represents one mail domain: it groups the aliases of the
 * domain and the account that receives unroutable messages (catch-all
 * replacement). The technical transport (fetching, sending, ...) is
 * provided by connector plugins, whose settings are stored in
 * ConnectorConfig.
 */
#[ORM\Entity(repositoryClass: ConnectorRepository::class)]
#[ORM\Table(name: 'connector')]
#[ORM\HasLifecycleCallbacks]
class Connector
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $name;
    #[ORM\Column(type: 'string', length: 190)]
    private string $domain;
    /**
     * Account that receives every message of this connector that matches
     * none of the exact aliases (catch-all replacement).
     */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'default_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $defaultAccount = null;
    /**
     * @var Collection<int, AccountAlias>
     */
    #[ORM\OneToMany(mappedBy: 'connector', targetEntity: AccountAlias::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['localPart' => 'ASC'])]
    private Collection $aliases;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->aliases = new ArrayCollection();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = strtolower(trim($domain));

        return $this;
    }

    public function getDefaultAccount(): ?Account
    {
        return $this->defaultAccount;
    }

    public function setDefaultAccount(?Account $account): self
    {
        $this->defaultAccount = $account;

        return $this;
    }

    /**
     * @return Collection<int, AccountAlias>
     */
    public function getAliases(): Collection
    {
        return $this->aliases;
    }

    public function addAlias(AccountAlias $alias): self
    {
        if ($this->aliases->contains($alias)) {
            return $this;
        }

        $this->aliases->add($alias);
        $alias->setConnector($this);

        return $this;
    }

    public function removeAlias(AccountAlias $alias): self
    {
        if (!$this->aliases->removeElement($alias)) {
            return $this;
        }

        if ($alias->getConnector() === $this) {
            $alias->setConnector(null);
        }

        return $this;
    }

    public function getAliasByLocalPart(string $localPart): ?AccountAlias
    {
        $needle = strtolower(trim($localPart));
        foreach ($this->aliases as $alias) {
            if ($alias->getLocalPart() === $needle) {
                return $alias;
            }
        }

        return null;
    }
}
