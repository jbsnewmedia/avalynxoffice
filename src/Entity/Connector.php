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
 * A connector is a standalone mail account on a mail server: it fetches
 * all mail for one domain via POP3 and sends via SMTP. The assignment of
 * single addresses (aliases) to accounts is stored in AccountAlias.
 */
#[ORM\Entity(repositoryClass: ConnectorRepository::class)]
#[ORM\Table(name: 'connector')]
#[ORM\UniqueConstraint(name: 'uniq_connector_domain', columns: ['domain'])]
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
    #[ORM\Column(name: 'pop3_host', type: 'string', length: 190, nullable: true)]
    private ?string $pop3Host = null;
    #[ORM\Column(name: 'pop3_port', type: 'integer', nullable: true)]
    private ?int $pop3Port = null;
    #[ORM\Column(name: 'pop3_username', type: 'string', length: 190, nullable: true)]
    private ?string $pop3Username = null;
    #[ORM\Column(name: 'pop3_password', type: 'string', length: 255, nullable: true)]
    private ?string $pop3Password = null;
    #[ORM\Column(name: 'pop3_security', type: 'string', length: 20, nullable: true)]
    private ?string $pop3Security = null;
    #[ORM\Column(name: 'pop3_delete_after_fetch', type: 'boolean', options: ['default' => true])]
    private bool $pop3DeleteAfterFetch = true;
    #[ORM\Column(name: 'smtp_host', type: 'string', length: 190, nullable: true)]
    private ?string $smtpHost = null;
    #[ORM\Column(name: 'smtp_port', type: 'integer', nullable: true)]
    private ?int $smtpPort = null;
    #[ORM\Column(name: 'smtp_username', type: 'string', length: 190, nullable: true)]
    private ?string $smtpUsername = null;
    #[ORM\Column(name: 'smtp_password', type: 'string', length: 255, nullable: true)]
    private ?string $smtpPassword = null;
    #[ORM\Column(name: 'smtp_security', type: 'string', length: 20, nullable: true)]
    private ?string $smtpSecurity = null;

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

    public function getPop3Host(): ?string
    {
        return $this->pop3Host;
    }

    public function setPop3Host(?string $host): self
    {
        $this->pop3Host = $host;

        return $this;
    }

    public function getPop3Port(): ?int
    {
        return $this->pop3Port;
    }

    public function setPop3Port(?int $port): self
    {
        $this->pop3Port = $port;

        return $this;
    }

    public function getPop3Username(): ?string
    {
        return $this->pop3Username;
    }

    public function setPop3Username(?string $username): self
    {
        $this->pop3Username = $username;

        return $this;
    }

    public function getPop3Password(): ?string
    {
        return $this->pop3Password;
    }

    public function setPop3Password(?string $password): self
    {
        $this->pop3Password = $password;

        return $this;
    }

    public function getPop3Security(): ?string
    {
        return $this->pop3Security;
    }

    public function setPop3Security(?string $security): self
    {
        $this->pop3Security = $security;

        return $this;
    }

    public function getPop3DeleteAfterFetch(): bool
    {
        return $this->pop3DeleteAfterFetch;
    }

    public function setPop3DeleteAfterFetch(bool $delete): self
    {
        $this->pop3DeleteAfterFetch = $delete;

        return $this;
    }

    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $host): self
    {
        $this->smtpHost = $host;

        return $this;
    }

    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $port): self
    {
        $this->smtpPort = $port;

        return $this;
    }

    public function getSmtpUsername(): ?string
    {
        return $this->smtpUsername;
    }

    public function setSmtpUsername(?string $username): self
    {
        $this->smtpUsername = $username;

        return $this;
    }

    public function getSmtpPassword(): ?string
    {
        return $this->smtpPassword;
    }

    public function setSmtpPassword(?string $password): self
    {
        $this->smtpPassword = $password;

        return $this;
    }

    public function getSmtpSecurity(): ?string
    {
        return $this->smtpSecurity;
    }

    public function setSmtpSecurity(?string $security): self
    {
        $this->smtpSecurity = $security;

        return $this;
    }
}
