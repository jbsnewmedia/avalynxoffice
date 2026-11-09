<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\AddressRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ORM\Table(name: 'address')]
#[ORM\HasLifecycleCallbacks]
class Address
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $email;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $name = null;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isContact = false;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstname = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastname = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $middlename = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $nickname = null;
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $namePrefix = null;
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $nameSuffix = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $company = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $department = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $jobTitle = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $officeLocation = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $assistantName = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $managerName = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $spouseName = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $website = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $businessPhone = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $businessPhone2 = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $businessFaxNumber = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $mobilePhone = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $homePhone = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $homePhone2 = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $homeFaxNumber = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $carPhone = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $pagerNumber = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $radioNumber = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $assistantPhone = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $companyPhone = null;
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $mms = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $email2 = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $email3 = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $imAddress = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $imAddress2 = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $imAddress3 = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $customerId = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $governmentId = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $accountName = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $yomiCompany = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $yomiFirstname = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $yomiLastname = null;
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthday = null;
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $anniversary = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $businessStreet = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $businessCity = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $businessState = null;
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $businessPostalCode = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $businessCountry = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $homeStreet = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $homeCity = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $homeState = null;
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $homePostalCode = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $homeCountry = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $otherStreet = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $otherCity = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $otherState = null;
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $otherPostalCode = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $otherCountry = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;
    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $children = null;
    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $categories = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $picture = null;
    /**
     * @var Collection<int, EmailAddressLink>
     */
    #[ORM\OneToMany(mappedBy: 'address', targetEntity: EmailAddressLink::class, orphanRemoval: true)]
    private Collection $emailAddresses;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailAddresses = new ArrayCollection();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function isContact(): bool
    {
        return $this->isContact;
    }

    public function setIsContact(bool $isContact): self
    {
        $this->isContact = $isContact;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): self
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): self
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getMiddlename(): ?string
    {
        return $this->middlename;
    }

    public function setMiddlename(?string $middlename): self
    {
        $this->middlename = $middlename;

        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): self
    {
        $this->nickname = $nickname;

        return $this;
    }

    public function getNamePrefix(): ?string
    {
        return $this->namePrefix;
    }

    public function setNamePrefix(?string $namePrefix): self
    {
        $this->namePrefix = $namePrefix;

        return $this;
    }

    public function getNameSuffix(): ?string
    {
        return $this->nameSuffix;
    }

    public function setNameSuffix(?string $nameSuffix): self
    {
        $this->nameSuffix = $nameSuffix;

        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): self
    {
        $this->department = $department;

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): self
    {
        $this->jobTitle = $jobTitle;

        return $this;
    }

    public function getOfficeLocation(): ?string
    {
        return $this->officeLocation;
    }

    public function setOfficeLocation(?string $officeLocation): self
    {
        $this->officeLocation = $officeLocation;

        return $this;
    }

    public function getAssistantName(): ?string
    {
        return $this->assistantName;
    }

    public function setAssistantName(?string $assistantName): self
    {
        $this->assistantName = $assistantName;

        return $this;
    }

    public function getManagerName(): ?string
    {
        return $this->managerName;
    }

    public function setManagerName(?string $managerName): self
    {
        $this->managerName = $managerName;

        return $this;
    }

    public function getSpouseName(): ?string
    {
        return $this->spouseName;
    }

    public function setSpouseName(?string $spouseName): self
    {
        $this->spouseName = $spouseName;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;

        return $this;
    }

    public function getBusinessPhone(): ?string
    {
        return $this->businessPhone;
    }

    public function setBusinessPhone(?string $businessPhone): self
    {
        $this->businessPhone = $businessPhone;

        return $this;
    }

    public function getBusinessPhone2(): ?string
    {
        return $this->businessPhone2;
    }

    public function setBusinessPhone2(?string $businessPhone2): self
    {
        $this->businessPhone2 = $businessPhone2;

        return $this;
    }

    public function getBusinessFaxNumber(): ?string
    {
        return $this->businessFaxNumber;
    }

    public function setBusinessFaxNumber(?string $businessFaxNumber): self
    {
        $this->businessFaxNumber = $businessFaxNumber;

        return $this;
    }

    public function getMobilePhone(): ?string
    {
        return $this->mobilePhone;
    }

    public function setMobilePhone(?string $mobilePhone): self
    {
        $this->mobilePhone = $mobilePhone;

        return $this;
    }

    public function getHomePhone(): ?string
    {
        return $this->homePhone;
    }

    public function setHomePhone(?string $homePhone): self
    {
        $this->homePhone = $homePhone;

        return $this;
    }

    public function getHomePhone2(): ?string
    {
        return $this->homePhone2;
    }

    public function setHomePhone2(?string $homePhone2): self
    {
        $this->homePhone2 = $homePhone2;

        return $this;
    }

    public function getHomeFaxNumber(): ?string
    {
        return $this->homeFaxNumber;
    }

    public function setHomeFaxNumber(?string $homeFaxNumber): self
    {
        $this->homeFaxNumber = $homeFaxNumber;

        return $this;
    }

    public function getCarPhone(): ?string
    {
        return $this->carPhone;
    }

    public function setCarPhone(?string $carPhone): self
    {
        $this->carPhone = $carPhone;

        return $this;
    }

    public function getPagerNumber(): ?string
    {
        return $this->pagerNumber;
    }

    public function setPagerNumber(?string $pagerNumber): self
    {
        $this->pagerNumber = $pagerNumber;

        return $this;
    }

    public function getRadioNumber(): ?string
    {
        return $this->radioNumber;
    }

    public function setRadioNumber(?string $radioNumber): self
    {
        $this->radioNumber = $radioNumber;

        return $this;
    }

    public function getAssistantPhone(): ?string
    {
        return $this->assistantPhone;
    }

    public function setAssistantPhone(?string $assistantPhone): self
    {
        $this->assistantPhone = $assistantPhone;

        return $this;
    }

    public function getCompanyPhone(): ?string
    {
        return $this->companyPhone;
    }

    public function setCompanyPhone(?string $companyPhone): self
    {
        $this->companyPhone = $companyPhone;

        return $this;
    }

    public function getMms(): ?string
    {
        return $this->mms;
    }

    public function setMms(?string $mms): self
    {
        $this->mms = $mms;

        return $this;
    }

    public function getEmail2(): ?string
    {
        return $this->email2;
    }

    public function setEmail2(?string $email2): self
    {
        $this->email2 = $email2;

        return $this;
    }

    public function getEmail3(): ?string
    {
        return $this->email3;
    }

    public function setEmail3(?string $email3): self
    {
        $this->email3 = $email3;

        return $this;
    }

    public function getImAddress(): ?string
    {
        return $this->imAddress;
    }

    public function setImAddress(?string $imAddress): self
    {
        $this->imAddress = $imAddress;

        return $this;
    }

    public function getImAddress2(): ?string
    {
        return $this->imAddress2;
    }

    public function setImAddress2(?string $imAddress2): self
    {
        $this->imAddress2 = $imAddress2;

        return $this;
    }

    public function getImAddress3(): ?string
    {
        return $this->imAddress3;
    }

    public function setImAddress3(?string $imAddress3): self
    {
        $this->imAddress3 = $imAddress3;

        return $this;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): self
    {
        $this->customerId = $customerId;

        return $this;
    }

    public function getGovernmentId(): ?string
    {
        return $this->governmentId;
    }

    public function setGovernmentId(?string $governmentId): self
    {
        $this->governmentId = $governmentId;

        return $this;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function setAccountName(?string $accountName): self
    {
        $this->accountName = $accountName;

        return $this;
    }

    public function getYomiCompany(): ?string
    {
        return $this->yomiCompany;
    }

    public function setYomiCompany(?string $yomiCompany): self
    {
        $this->yomiCompany = $yomiCompany;

        return $this;
    }

    public function getYomiFirstname(): ?string
    {
        return $this->yomiFirstname;
    }

    public function setYomiFirstname(?string $yomiFirstname): self
    {
        $this->yomiFirstname = $yomiFirstname;

        return $this;
    }

    public function getYomiLastname(): ?string
    {
        return $this->yomiLastname;
    }

    public function setYomiLastname(?string $yomiLastname): self
    {
        $this->yomiLastname = $yomiLastname;

        return $this;
    }

    public function getBirthday(): ?\DateTimeImmutable
    {
        return $this->birthday;
    }

    public function setBirthday(?\DateTimeImmutable $birthday): self
    {
        $this->birthday = $birthday;

        return $this;
    }

    public function getAnniversary(): ?\DateTimeImmutable
    {
        return $this->anniversary;
    }

    public function setAnniversary(?\DateTimeImmutable $anniversary): self
    {
        $this->anniversary = $anniversary;

        return $this;
    }

    public function getBusinessStreet(): ?string
    {
        return $this->businessStreet;
    }

    public function setBusinessStreet(?string $businessStreet): self
    {
        $this->businessStreet = $businessStreet;

        return $this;
    }

    public function getBusinessCity(): ?string
    {
        return $this->businessCity;
    }

    public function setBusinessCity(?string $businessCity): self
    {
        $this->businessCity = $businessCity;

        return $this;
    }

    public function getBusinessState(): ?string
    {
        return $this->businessState;
    }

    public function setBusinessState(?string $businessState): self
    {
        $this->businessState = $businessState;

        return $this;
    }

    public function getBusinessPostalCode(): ?string
    {
        return $this->businessPostalCode;
    }

    public function setBusinessPostalCode(?string $businessPostalCode): self
    {
        $this->businessPostalCode = $businessPostalCode;

        return $this;
    }

    public function getBusinessCountry(): ?string
    {
        return $this->businessCountry;
    }

    public function setBusinessCountry(?string $businessCountry): self
    {
        $this->businessCountry = $businessCountry;

        return $this;
    }

    public function getHomeStreet(): ?string
    {
        return $this->homeStreet;
    }

    public function setHomeStreet(?string $homeStreet): self
    {
        $this->homeStreet = $homeStreet;

        return $this;
    }

    public function getHomeCity(): ?string
    {
        return $this->homeCity;
    }

    public function setHomeCity(?string $homeCity): self
    {
        $this->homeCity = $homeCity;

        return $this;
    }

    public function getHomeState(): ?string
    {
        return $this->homeState;
    }

    public function setHomeState(?string $homeState): self
    {
        $this->homeState = $homeState;

        return $this;
    }

    public function getHomePostalCode(): ?string
    {
        return $this->homePostalCode;
    }

    public function setHomePostalCode(?string $homePostalCode): self
    {
        $this->homePostalCode = $homePostalCode;

        return $this;
    }

    public function getHomeCountry(): ?string
    {
        return $this->homeCountry;
    }

    public function setHomeCountry(?string $homeCountry): self
    {
        $this->homeCountry = $homeCountry;

        return $this;
    }

    public function getOtherStreet(): ?string
    {
        return $this->otherStreet;
    }

    public function setOtherStreet(?string $otherStreet): self
    {
        $this->otherStreet = $otherStreet;

        return $this;
    }

    public function getOtherCity(): ?string
    {
        return $this->otherCity;
    }

    public function setOtherCity(?string $otherCity): self
    {
        $this->otherCity = $otherCity;

        return $this;
    }

    public function getOtherState(): ?string
    {
        return $this->otherState;
    }

    public function setOtherState(?string $otherState): self
    {
        $this->otherState = $otherState;

        return $this;
    }

    public function getOtherPostalCode(): ?string
    {
        return $this->otherPostalCode;
    }

    public function setOtherPostalCode(?string $otherPostalCode): self
    {
        $this->otherPostalCode = $otherPostalCode;

        return $this;
    }

    public function getOtherCountry(): ?string
    {
        return $this->otherCountry;
    }

    public function setOtherCountry(?string $otherCountry): self
    {
        $this->otherCountry = $otherCountry;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getChildren(): ?array
    {
        return $this->children;
    }

    /**
     * @param list<string>|null $children
     */
    public function setChildren(?array $children): self
    {
        $this->children = $children;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getCategories(): ?array
    {
        return $this->categories;
    }

    /**
     * @param list<string>|null $categories
     */
    public function setCategories(?array $categories): self
    {
        $this->categories = $categories;

        return $this;
    }

    public function getPicture(): ?string
    {
        return $this->picture;
    }

    public function setPicture(?string $picture): self
    {
        $this->picture = $picture;

        return $this;
    }

    public function displayName(): string
    {
        if (null !== $this->name && '' !== $this->name) {
            return $this->name;
        }

        $names = trim((string) $this->firstname.' '.(string) $this->lastname);

        return '' !== $names ? $names : $this->email;
    }

    /**
     * @return Collection<int, EmailAddressLink>
     */
    public function getEmailAddresses(): Collection
    {
        return $this->emailAddresses;
    }
}
