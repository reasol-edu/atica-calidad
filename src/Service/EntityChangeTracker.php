<?php

declare(strict_types=1);

namespace App\Service;

final class EntityChangeTracker
{
    /**
     * @param string[] $fields
     * @return array<string, mixed>
     */
    public function snapshot(object $entity, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->normalize($this->read($entity, $field));
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $before
     * @param string[] $fields
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function diff(array $before, object $entity, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $after = $this->normalize($this->read($entity, $field));
            if (($before[$field] ?? null) !== $after) {
                $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $after];
            }
        }

        return $changes;
    }

    private function read(object $entity, string $field): mixed
    {
        $getter = 'get' . ucfirst($field);
        if (!method_exists($entity, $getter)) {
            $getter = 'is' . ucfirst($field);
        }
        if (!method_exists($entity, $getter)) {
            $getter = $field;
        }

        return $entity->$getter();
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum        => $value->value,
            $value instanceof \Stringable        => (string) $value,
            default                              => $value,
        };
    }
}
