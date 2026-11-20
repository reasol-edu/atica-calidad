<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;

/**
 * The lowest revision number a document of a centre may have: 1 (the first revision is "v1") or 0 (the first is "v0", the way
 * many quality systems number the original edition). It is the setting `documents.min_revision`, set globally and overridable
 * per centre (a global value that is locked wins). Every place that proposes or checks a revision number asks here.
 */
final class DocumentVersioning
{
    public const string SETTING = 'documents.min_revision';

    /** What a centre gets when nobody set anything, and what an unreadable value falls back to. */
    public const int DEFAULT = 1;

    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    /** 0 or 1. */
    public function minimum(EducationalCentre $centre): int
    {
        return $this->settings->getForCentre(self::SETTING, $centre) === '0' ? 0 : self::DEFAULT;
    }
}
