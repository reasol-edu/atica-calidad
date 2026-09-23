<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Trashable;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Hides whatever is in the trash (Trashable) from every query — lists, searches, reports, joins,
 * lazy-loaded collections — so the rest of the app never has to think about it. Enabled by
 * default (config/packages/doctrine.yaml); only TrashService switches it off, to show and act on
 * the trash itself.
 */
final class TrashFilter extends SQLFilter
{
    public const string NAME = 'trash';

    /** @param ClassMetadata<object> $targetEntity */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->reflClass?->implementsInterface(Trashable::class)) {
            return '';
        }

        return $targetTableAlias . '.deleted_at IS NULL';
    }
}
