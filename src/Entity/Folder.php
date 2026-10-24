<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Repository\FolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: FolderRepository::class)]
#[ORM\Table(name: 'folder')]
#[ORM\HasLifecycleCallbacks]
class Folder
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $name;
    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $functions = [];
    /**
     * @var Collection<int, FolderAccount>
     */
    #[ORM\OneToMany(mappedBy: 'folder', targetEntity: FolderAccount::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $accountLinks;
    /**
     * @var Collection<int, EmailFolder>
     */
    #[ORM\OneToMany(mappedBy: 'folder', targetEntity: EmailFolder::class, orphanRemoval: true)]
    private Collection $emailFolders;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->accountLinks = new ArrayCollection();
        $this->emailFolders = new ArrayCollection();
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

    /**
     * @return array<string>
     */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /**
     * @param array<string|FolderFunction> $functions
     */
    public function setFunctions(array $functions): self
    {
        $normalized = [];
        foreach ($functions as $function) {
            $value = $function instanceof FolderFunction ? $function->value : strtolower(trim((string) $function));
            if ('' === $value) {
                continue;
            }
            $normalized[] = $value;
        }

        $this->functions = array_values(array_unique($normalized));

        return $this;
    }

    public function addFunction(FolderFunction|string $function): self
    {
        $value = $function instanceof FolderFunction ? $function->value : strtolower(trim($function));
        if ('' === $value || in_array($value, $this->functions, true)) {
            return $this;
        }

        $this->functions[] = $value;

        return $this;
    }

    public function removeFunction(FolderFunction|string $function): self
    {
        $value = $function instanceof FolderFunction ? $function->value : strtolower(trim($function));
        $this->functions = array_values(array_filter(
            $this->functions,
            static fn (string $existing): bool => $existing !== $value,
        ));

        return $this;
    }

    public function hasFunction(FolderFunction|string $function): bool
    {
        $value = $function instanceof FolderFunction ? $function->value : strtolower(trim($function));

        return in_array($value, $this->functions, true);
    }

    public function hasAnyFunction(): bool
    {
        return [] !== $this->functions;
    }

    /**
     * @return Collection<int, FolderAccount>
     */
    public function getAccountLinks(): Collection
    {
        return $this->accountLinks;
    }

    /**
     * @return Collection<int, Account>
     */
    public function getAccounts(): Collection
    {
        /** @var ArrayCollection<int, Account> $accounts */
        $accounts = new ArrayCollection();
        foreach ($this->accountLinks as $link) {
            $mb = $link->getAccount();
            if ($mb && !$accounts->contains($mb)) {
                $accounts->add($mb);
            }
        }

        return $accounts;
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    public function getEmailFolders(): Collection
    {
        return $this->emailFolders;
    }
}
