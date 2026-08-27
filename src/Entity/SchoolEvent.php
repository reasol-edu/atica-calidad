<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SchoolEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SchoolEventRepository::class)]
class SchoolEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AcademicYear $academicYear;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endTime;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column]
    private bool $general = false;

    /** @var Collection<int, SchoolEventProfile> */
    #[ORM\OneToMany(targetEntity: SchoolEventProfile::class, mappedBy: 'schoolEvent', cascade: ['persist'], orphanRemoval: true)]
    private Collection $profileRestrictions;

    public function __construct()
    {
        $this->profileRestrictions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): \DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function isGeneral(): bool
    {
        return $this->general;
    }

    public function setGeneral(bool $general): static
    {
        $this->general = $general;

        return $this;
    }

    /** @return Collection<int, SchoolEventProfile> */
    public function getProfileRestrictions(): Collection
    {
        return $this->profileRestrictions;
    }

    public function hasProfileRestriction(SpecificProfile $profile, ?ListItem $listItem): bool
    {
        return $this->profileRestrictions->exists(
            static fn (int $i, SchoolEventProfile $r): bool =>
                $r->getSpecificProfile() === $profile && $r->getListItem() === $listItem
        );
    }

    public function addProfileRestriction(SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        if (!$this->hasProfileRestriction($profile, $listItem)) {
            $this->profileRestrictions->add(new SchoolEventProfile($this, $profile, $listItem));
        }

        return $this;
    }

    public function removeProfileRestriction(SchoolEventProfile $restriction): static
    {
        $this->profileRestrictions->removeElement($restriction);

        return $this;
    }
}
