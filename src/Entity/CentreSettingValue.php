<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CentreSettingValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CentreSettingValueRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_centre_setting_def_centre', columns: ['definition_id', 'centre_id'])]
class CentreSettingValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SettingDefinition $definition;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private EducationalCentre $centre;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    #[ORM\Column]
    private bool $locked = false;

    #[ORM\ManyToOne]
    private ?SettingFile $file = null;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDefinition(): SettingDefinition
    {
        return $this->definition;
    }

    public function setDefinition(SettingDefinition $definition): static
    {
        $this->definition = $definition;

        return $this;
    }

    public function getCentre(): EducationalCentre
    {
        return $this->centre;
    }

    public function setCentre(EducationalCentre $centre): static
    {
        $this->centre = $centre;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    public function getFile(): ?SettingFile
    {
        return $this->file;
    }

    public function setFile(?SettingFile $file): static
    {
        $this->file = $file;

        return $this;
    }
}
