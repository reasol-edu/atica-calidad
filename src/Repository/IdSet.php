<?php

declare(strict_types=1);

namespace App\Repository;

use Symfony\Component\Uid\Uuid;

/** What a "which of these ids are used" query returns, as a set to test membership in memory. */
final class IdSet
{
    /**
     * @param array<mixed> $ids the single-column result of a query on an id (Uuid objects, or strings)
     *
     * @return array<string, true> RFC4122 ids
     */
    public static function of(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            if (is_resource($id)) {
                $id = stream_get_contents($id);
            }
            // The id as the engine stores it: a Uuid object once the type converted it, 16 raw bytes (SQLite, MySQL) or its text (PostgreSQL).
            $key = match (true) {
                $id instanceof Uuid => $id->toRfc4122(),
                is_string($id) && strlen($id) === 16 => Uuid::fromBinary($id)->toRfc4122(),
                is_string($id) => strtolower($id),
                default => '',
            };
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }
}
