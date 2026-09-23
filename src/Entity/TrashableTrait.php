<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Trashable columns. Who deleted it is kept as a name, not a link to the teacher: it's only
 * shown in the trash, and must survive that teacher's own account being removed meanwhile.
 */
trait TrashableTrait
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deletedByName = null;

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getDeletedByName(): ?string
    {
        return $this->deletedByName;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt !== null;
    }

    public function moveToTrash(\DateTimeImmutable $at, Teacher $by): void
    {
        $this->deletedAt     = $at;
        $this->deletedByName = $by->getName()->getLastName() . ', ' . $by->getName()->getFirstName();
    }

    public function restoreFromTrash(): void
    {
        $this->deletedAt     = null;
        $this->deletedByName = null;
    }
}
