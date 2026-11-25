<?php

declare(strict_types=1);

namespace App\Model;

/** One element of a SenecaListImportPlan whose own teachers the import changes, for the preview: who comes in and who leaves. */
final class SenecaListImportTeacherChange
{
    /**
     * @param string[]     $path    names from (but not including) the import's root down to the element
     * @param list<string> $added   full names of the teachers it gains
     * @param list<string> $removed full names of the teachers it loses
     */
    public function __construct(
        public readonly array $path,
        public readonly array $added,
        public readonly array $removed,
    ) {}

    public function label(): string
    {
        return implode(' › ', $this->path);
    }
}
