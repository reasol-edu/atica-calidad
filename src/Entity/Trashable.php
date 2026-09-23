<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * An entity that goes to the trash ("papelera") instead of being deleted straight away: it stays
 * in the database, hidden from every query by TrashFilter, until it's restored or purged — by
 * hand, or by TrashService::purgeExpired() once the centre's retention days have passed. See
 * TrashableTrait.
 */
interface Trashable
{
    public function getDeletedAt(): ?\DateTimeImmutable;

    public function getDeletedByName(): ?string;

    public function isTrashed(): bool;

    public function moveToTrash(\DateTimeImmutable $at, Teacher $by): void;

    public function restoreFromTrash(): void;
}
