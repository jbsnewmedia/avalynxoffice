<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\BodyContentType;
use App\Repository\EmailBodyLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: EmailBodyLinkRepository::class)]
#[ORM\Table(name: 'email_body_link')]
#[ORM\HasLifecycleCallbacks]
class EmailBodyLink
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }
    #[ORM\ManyToOne(inversedBy: 'emailBodies')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Email $email = null;
    #[ORM\ManyToOne(inversedBy: 'emailBodies')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Body $body = null;
    #[ORM\Column(type: 'string', enumType: BodyContentType::class)]
    private BodyContentType $contentType;

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

    public function getBody(): ?Body
    {
        return $this->body;
    }

    public function setBody(?Body $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getContentType(): BodyContentType
    {
        return $this->contentType;
    }

    public function setContentType(BodyContentType $contentType): self
    {
        $this->contentType = $contentType;

        return $this;
    }
}
