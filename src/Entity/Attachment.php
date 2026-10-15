<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttachmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'attachment')]
#[ORM\HasLifecycleCallbacks]
class Attachment
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 255)]
    private string $name;
    #[ORM\Column(type: 'integer')]
    private int $size;
    #[ORM\Column(name: 'mime_type', type: 'string', length: 190)]
    private string $mimeType;
    #[ORM\Column(type: 'string', length: 40)]
    private string $sha1;
    #[ORM\Column(name: 'file_path', type: 'string', length: 255)]
    private string $filePath;
    /**
     * @var Collection<int, EmailAttachmentLink>
     */
    #[ORM\OneToMany(mappedBy: 'attachment', targetEntity: EmailAttachmentLink::class, orphanRemoval: true)]
    private Collection $emailAttachments;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailAttachments = new ArrayCollection();
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

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSha1(): string
    {
        return $this->sha1;
    }

    public function setSha1(string $sha1): self
    {
        $this->sha1 = $sha1;

        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): self
    {
        $this->filePath = $filePath;

        return $this;
    }

    /**
     * @return Collection<int, EmailAttachmentLink>
     */
    public function getEmailAttachments(): Collection
    {
        return $this->emailAttachments;
    }
}
