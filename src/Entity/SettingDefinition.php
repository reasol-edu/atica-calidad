<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingDefinitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SettingDefinitionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_setting_definition_key', columns: ['key'])]
class SettingDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $key;

    #[ORM\Column(enumType: SettingType::class)]
    private SettingType $type;

    #[ORM\Column(type: Types::TEXT)]
    private string $defaultValue;

    #[ORM\Column]
    private bool $globalScope = false;

    #[ORM\Column]
    private bool $centreScope = false;

    #[ORM\Column]
    private bool $teacherScope = false;

    #[ORM\Column(nullable: true)]
    private ?int $minValue = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxValue = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $choices = null;

    #[ORM\Column(length: 100)]
    private string $category = '';

    #[ORM\Column]
    private int $categoryOrder = 0;

    #[ORM\Column]
    private int $position = 0;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getType(): SettingType
    {
        return $this->type;
    }

    public function setType(SettingType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDefaultValue(): string
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(string $defaultValue): static
    {
        $this->defaultValue = $defaultValue;

        return $this;
    }

    public function isGlobalScope(): bool
    {
        return $this->globalScope;
    }

    public function setGlobalScope(bool $globalScope): static
    {
        $this->globalScope = $globalScope;

        return $this;
    }

    public function isCentreScope(): bool
    {
        return $this->centreScope;
    }

    public function setCentreScope(bool $centreScope): static
    {
        $this->centreScope = $centreScope;

        return $this;
    }

    public function isTeacherScope(): bool
    {
        return $this->teacherScope;
    }

    public function setTeacherScope(bool $teacherScope): static
    {
        $this->teacherScope = $teacherScope;

        return $this;
    }

    public function getMinValue(): ?int
    {
        return $this->minValue;
    }

    public function setMinValue(?int $minValue): static
    {
        $this->minValue = $minValue;

        return $this;
    }

    public function getMaxValue(): ?int
    {
        return $this->maxValue;
    }

    public function setMaxValue(?int $maxValue): static
    {
        $this->maxValue = $maxValue;

        return $this;
    }

    public function getChoices(): ?string
    {
        return $this->choices;
    }

    public function setChoices(?string $choices): static
    {
        $this->choices = $choices;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCategoryOrder(): int
    {
        return $this->categoryOrder;
    }

    public function setCategoryOrder(int $categoryOrder): static
    {
        $this->categoryOrder = $categoryOrder;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** @return list<string> */
    public function getChoicesArray(): array
    {
        if ($this->choices === null || $this->choices === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->choices)), static fn (string $c): bool => $c !== ''));
    }

    /**
     * Returns true if $value is acceptable for this definition.
     * Booleans must be 'true' or 'false'.
     * Integers must be numeric, non-decimal, and within [minValue, maxValue] when set.
     * Strings must have a length within [minValue, maxValue] when set; empty is always valid.
     * Pdf-typed values hold the display filename and are validated the same way as
     * strings; the actual file is validated and stored separately (see SettingFile).
     */
    public function isValueValid(string $value): bool
    {
        return match ($this->type) {
            SettingType::Boolean => in_array($value, ['true', 'false'], true),
            SettingType::Integer => $this->isIntValueValid($value),
            SettingType::String,
            SettingType::RichText,
            SettingType::Pdf     => $this->isStringValueValid($value),
            SettingType::Choice  => in_array($value, $this->getChoicesArray(), true),
        };
    }

    private function isIntValueValid(string $value): bool
    {
        if (!is_numeric($value) || str_contains($value, '.')) {
            return false;
        }

        $int = (int) $value;

        if ($this->minValue !== null && $int < $this->minValue) {
            return false;
        }

        if ($this->maxValue !== null && $int > $this->maxValue) {
            return false;
        }

        return true;
    }

    private function isStringValueValid(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $len = mb_strlen($value);

        if ($this->minValue !== null && $len < $this->minValue) {
            return false;
        }

        if ($this->maxValue !== null && $len > $this->maxValue) {
            return false;
        }

        return true;
    }

    /** Returns the typed default value (int, bool or string). */
    public function getCastedDefaultValue(): mixed
    {
        return match ($this->type) {
            SettingType::Boolean => $this->defaultValue === 'true',
            SettingType::Integer => (int) $this->defaultValue,
            SettingType::String,
            SettingType::Choice,
            SettingType::RichText,
            SettingType::Pdf => $this->defaultValue,
        };
    }
}
