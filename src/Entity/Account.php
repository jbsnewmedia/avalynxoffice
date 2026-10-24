<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account')]
#[ORM\HasLifecycleCallbacks]
class Account
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $name;
    #[ORM\Column(type: 'string', length: 190)]
    private string $email;
    /**
     * @var Collection<int, FolderAccount>
     */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: FolderAccount::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $folderLinks;
    /**
     * @var Collection<int, UserAccount>
     */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: UserAccount::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userLinks;
    /**
     * @var Collection<int, AccountAlias>
     */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: AccountAlias::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['localPart' => 'ASC'])]
    private Collection $aliasLinks;
    /**
     * @var Collection<int, AccountAddress>
     */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: AccountAddress::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $addressLinks;
    #[ORM\ManyToOne(targetEntity: Folder::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'inbox_folder_id', referencedColumnName: 'id', nullable: true)]
    private ?Folder $inboxFolder = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->folderLinks = new ArrayCollection();
        $this->userLinks = new ArrayCollection();
        $this->aliasLinks = new ArrayCollection();
        $this->addressLinks = new ArrayCollection();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @return Collection<int, FolderAccount>
     */
    public function getFolderLinks(): Collection
    {
        return $this->folderLinks;
    }

    /**
     * @return Collection<int, Folder>
     */
    public function getFolders(): Collection
    {
        /** @var ArrayCollection<int, Folder> $folders */
        $folders = new ArrayCollection();
        foreach ($this->folderLinks as $link) {
            $folder = $link->getFolder();
            if ($folder && !$folders->contains($folder)) {
                $folders->add($folder);
            }
        }

        return $folders;
    }

    public function addFolder(Folder $folder, int $sortOrder = 0): self
    {
        foreach ($this->folderLinks as $link) {
            if ($link->getFolder() === $folder) {
                $link->setSortOrder($sortOrder);

                return $this;
            }
        }

        $link = new FolderAccount();
        $link->setAccount($this);
        $link->setFolder($folder);
        $link->setSortOrder($sortOrder);
        $this->folderLinks->add($link);

        return $this;
    }

    public function removeFolder(Folder $folder): self
    {
        foreach ($this->folderLinks as $link) {
            if ($link->getFolder() === $folder) {
                $this->folderLinks->removeElement($link);
                break;
            }
        }

        if ($this->inboxFolder === $folder) {
            $this->inboxFolder = null;
        }

        return $this;
    }

    /**
     * @return Collection<int, UserAccount>
     */
    public function getUserLinks(): Collection
    {
        return $this->userLinks;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        /** @var ArrayCollection<int, User> $users */
        $users = new ArrayCollection();
        foreach ($this->userLinks as $link) {
            $user = $link->getUser();
            if ($user && !$users->contains($user)) {
                $users->add($user);
            }
        }

        return $users;
    }

    public function addUser(User $user): self
    {
        foreach ($this->userLinks as $link) {
            if ($link->getUser() === $user) {
                return $this;
            }
        }

        $link = new UserAccount();
        $link->setUser($user);
        $link->setAccount($this);
        $this->userLinks->add($link);

        return $this;
    }

    public function removeUser(User $user): self
    {
        foreach ($this->userLinks as $link) {
            if ($link->getUser() === $user) {
                $this->userLinks->removeElement($link);
                break;
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, AccountAlias>
     */
    public function getAliasLinks(): Collection
    {
        return $this->aliasLinks;
    }

    /**
     * @return Collection<int, AccountAlias>
     */
    public function getAliases(): Collection
    {
        return $this->aliasLinks;
    }

    public function addAlias(AccountAlias $alias): self
    {
        if ($this->aliasLinks->contains($alias)) {
            return $this;
        }

        $this->aliasLinks->add($alias);
        $alias->setAccount($this);

        return $this;
    }

    public function removeAlias(AccountAlias $alias): self
    {
        if (!$this->aliasLinks->removeElement($alias)) {
            return $this;
        }

        if ($alias->getAccount() === $this) {
            $alias->setAccount(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, AccountAddress>
     */
    public function getAddressLinks(): Collection
    {
        return $this->addressLinks;
    }

    /**
     * @return Collection<int, Address>
     */
    public function getAddresses(): Collection
    {
        /** @var ArrayCollection<int, Address> $addresses */
        $addresses = new ArrayCollection();
        foreach ($this->addressLinks as $link) {
            $address = $link->getAddress();
            if ($address && !$addresses->contains($address)) {
                $addresses->add($address);
            }
        }

        return $addresses;
    }

    public function addAddress(Address $address): self
    {
        foreach ($this->addressLinks as $link) {
            if ($link->getAddress() === $address) {
                return $this;
            }
        }

        $link = new AccountAddress();
        $link->setAccount($this);
        $link->setAddress($address);
        $this->addressLinks->add($link);

        return $this;
    }

    public function removeAddress(Address $address): self
    {
        foreach ($this->addressLinks as $link) {
            if ($link->getAddress() === $address) {
                $this->addressLinks->removeElement($link);
                break;
            }
        }

        return $this;
    }

    public function getPrimaryAddress(): ?Address
    {
        foreach ($this->addressLinks as $link) {
            $address = $link->getAddress();
            if ($address instanceof Address) {
                return $address;
            }
        }

        return null;
    }

    public function getInboxFolder(): ?Folder
    {
        return $this->inboxFolder;
    }

    public function setInboxFolder(?Folder $inboxFolder): self
    {
        $this->inboxFolder = $inboxFolder;

        return $this;
    }
}
