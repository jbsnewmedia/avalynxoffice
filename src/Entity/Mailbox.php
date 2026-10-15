<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MailboxRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: MailboxRepository::class)]
#[ORM\Table(name: 'mailbox')]
#[ORM\HasLifecycleCallbacks]
class Mailbox
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
     * @var Collection<int, FolderMailbox>
     */
    #[ORM\OneToMany(mappedBy: 'mailbox', targetEntity: FolderMailbox::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $folderLinks;
    /**
     * @var Collection<int, UserMailbox>
     */
    #[ORM\OneToMany(mappedBy: 'mailbox', targetEntity: UserMailbox::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userLinks;
    /**
     * @var Collection<int, MailboxConnector>
     */
    #[ORM\OneToMany(mappedBy: 'mailbox', targetEntity: MailboxConnector::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['isPrimary' => 'DESC', 'name' => 'ASC'])]
    private Collection $connectors;
    /**
     * @var Collection<int, MailboxAddress>
     */
    #[ORM\OneToMany(mappedBy: 'mailbox', targetEntity: MailboxAddress::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $addressLinks;
    #[ORM\ManyToOne(targetEntity: Folder::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'inbox_folder_id', referencedColumnName: 'id', nullable: true)]
    private ?Folder $inboxFolder = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->folderLinks = new ArrayCollection();
        $this->userLinks = new ArrayCollection();
        $this->connectors = new ArrayCollection();
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
     * @return Collection<int, FolderMailbox>
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

        $link = new FolderMailbox();
        $link->setMailbox($this);
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
     * @return Collection<int, UserMailbox>
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

        $link = new UserMailbox();
        $link->setUser($user);
        $link->setMailbox($this);
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
     * @return Collection<int, MailboxConnector>
     */
    public function getConnectors(): Collection
    {
        return $this->connectors;
    }

    public function addConnector(MailboxConnector $connector): self
    {
        if ($this->connectors->contains($connector)) {
            return $this;
        }

        if ($connector->isPrimary()) {
            $this->clearPrimaryConnector();
        } elseif ($this->connectors->isEmpty()) {
            $connector->setIsPrimary(true);
        }

        $this->connectors->add($connector);
        $connector->setMailbox($this);

        return $this;
    }

    public function removeConnector(MailboxConnector $connector): self
    {
        if (!$this->connectors->removeElement($connector)) {
            return $this;
        }

        if ($connector->getMailbox() === $this) {
            $connector->setMailbox(null);
        }

        if ($connector->isPrimary()) {
            $next = $this->connectors->first();
            if ($next instanceof MailboxConnector) {
                $next->setIsPrimary(true);
            }
        }

        return $this;
    }

    public function getPrimaryConnector(): ?MailboxConnector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->isPrimary()) {
                return $connector;
            }
        }

        $first = $this->connectors->first();

        return $first instanceof MailboxConnector ? $first : null;
    }

    /**
     * @return Collection<int, MailboxAddress>
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

        $link = new MailboxAddress();
        $link->setMailbox($this);
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

    private function clearPrimaryConnector(): void
    {
        foreach ($this->connectors as $connector) {
            if ($connector->isPrimary()) {
                $connector->setIsPrimary(false);
            }
        }
    }
}
