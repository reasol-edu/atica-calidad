<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\TrashFilter;

/**
 * For the few repository queries that must see inside the trash, which TrashFilter hides
 * everywhere else: runs $query with the filter off, and switches it back on afterwards.
 */
trait TrashQueryTrait
{
    /**
     * @template T
     *
     * @param callable(): T $query
     *
     * @return T
     */
    private function withTrash(callable $query): mixed
    {
        $filters = $this->getEntityManager()->getFilters();
        $enabled = $filters->isEnabled(TrashFilter::NAME);
        if ($enabled) {
            $filters->disable(TrashFilter::NAME);
        }

        try {
            return $query();
        } finally {
            if ($enabled) {
                $filters->enable(TrashFilter::NAME);
            }
        }
    }
}
