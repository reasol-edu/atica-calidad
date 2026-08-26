<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeacherSettingValueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TeacherSettingValueRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_teacher_setting_def_teacher', columns: ['definition_id', 'teacher_id'])]
class TeacherSettingValue
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
    private Teacher $teacher;

    #[ORM\Column(length: 255)]
    private string $value;

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

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function setTeacher(Teacher $teacher): static
    {
        $this->teacher = $teacher;

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
