<?php

declare(strict_types=1);

namespace App\Model;

/**
 * What a Séneca list import (see SenecaListImporter) would do, for the confirmation preview.
 * $deactivations and $reactivations always happen; whether $deletions actually get deleted (vs.
 * also just deactivated) is the user's choice on the preview screen — see
 * SenecaListImporter::apply()'s $deleteUnused parameter.
 */
final class SenecaListImportPlan
{
    /**
     * @param SenecaListImportPlanItem[] $additions      does not exist yet, would be created
     * @param SenecaListImportPlanItem[] $deletions       not in the import and unused — deleted, unless the user opts to deactivate them instead
     * @param SenecaListImportPlanItem[] $deactivations   not in the import but currently in use (a profile, an assignment, or a delivered document) — can only be deactivated, never deleted
     * @param SenecaListImportPlanItem[] $reactivations   in the import and currently inactive — reactivated
     */
    public function __construct(
        public readonly string $rootName,
        public readonly bool $rootExists,
        public readonly array $additions,
        public readonly array $deletions,
        public readonly array $deactivations,
        public readonly array $reactivations,
    ) {}

    public function isEmpty(): bool
    {
        return $this->additions === []
            && $this->deletions === []
            && $this->deactivations === []
            && $this->reactivations === [];
    }
}
