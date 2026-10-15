<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailFolderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: EmailFolderRepository::class)]
#[ORM\Table(name: 'email_folder')]
#[ORM\HasLifecycleCallbacks]
class EmailFolder
{
    use TimestampableTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(inversedBy: 'emailFolders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Email $email = null;
    #[ORM\ManyToOne(inversedBy: 'emailFolders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Folder $folder = null;

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

    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    public function setFolder(?Folder $folder): self
    {
        $this->folder = $folder;

        return $this;
    }
}
