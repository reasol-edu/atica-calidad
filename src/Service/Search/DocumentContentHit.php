<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Document;

/** A document found by what is written inside it, with the words around the match. */
final readonly class DocumentContentHit
{
    /**
     * @param list<array{0: string, 1: bool}> $snippet  runs of text, the matches marked (see SnippetBuilder)
     * @param int|null                        $cycleYear cycle key of the academic year an activity submission belongs to; null for any other document
     * @param bool                            $past      a submission of an earlier course than the current one of its activity
     */
    public function __construct(
        public Document $document,
        public array $snippet,
        public ?int $cycleYear,
        public bool $past,
    ) {}
}
