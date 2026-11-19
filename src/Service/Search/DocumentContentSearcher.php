<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\DocumentFileTextRepository;
use App\Repository\DocumentRepository;
use App\Search\SnippetBuilder;
use App\Search\TextNormalizer;
use App\Service\ActivityFolderCycleFilter;
use App\Service\DocumentTreeAccessChecker;

/**
 * Search inside the text of the documents of a centre (the revision in force of each), for the ⌘K palette.
 *
 * Which course a document belongs to is not in the index, which is per file: a plain document has no course (its revision in
 * force is what counts) and an activity submission belongs to the cycle it was delivered for. By default a submission of an
 * earlier cycle of its activity is left out, like the folder screen does until another year is picked; "past" brings those
 * and the obsolete folders in. The current cycle depends on each activity's own dates and the clock, so it is decided here with
 * the same ActivityFolderCycleFilter the screen uses, after the query: candidates are read in pages until enough survive, and
 * what a teacher may see is decided by the same rule as the name search (DocumentSearchVisibility) before any text is read.
 */
final class DocumentContentSearcher
{
    /** Results returned. */
    public const int LIMIT = 5;

    /** Candidates read per round and how many rounds are tried to fill LIMIT. */
    private const int BATCH  = 40;
    private const int ROUNDS = 5;

    /** Shortest word searched inside documents: shorter ones match everything and cost a full scan for nothing. */
    public const int MIN_TERM = 3;

    /** Most words of a query that narrow a content search. */
    private const int MAX_TERMS = 6;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFileTextRepository $texts,
        private readonly DocumentSearchVisibility $visibility,
        private readonly ActivityFolderCycleFilter $cycles,
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    /** @return list<DocumentContentHit> */
    public function search(Teacher $teacher, EducationalCentre $centre, string $query, bool $includePast): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return [];
        }

        // An obsolete folder is only shown in the tree to whoever manages the centre's documents, so only they are told what is in one.
        $includeObsolete = $includePast && $this->access->isAdminOrQualityManager($teacher, $centre);

        /** @var list<array{0: Document, 1: int|null, 2: bool}> $found */
        $found  = [];
        $offset = 0;
        for ($round = 0; $round < self::ROUNDS && \count($found) < self::LIMIT; ++$round) {
            $batch = $this->documents->searchContentByCentre($centre, $terms, $includeObsolete, self::BATCH, $offset);
            $offset += self::BATCH;

            foreach ($batch as $document) {
                [$cycle, $past] = $this->cycleOf($document);
                if ($past && !$includePast) {
                    continue;
                }
                if (!$this->visibility->canSee($teacher, $document, $centre)) {
                    continue;
                }
                $found[] = [$document, $cycle, $past];
                if (\count($found) >= self::LIMIT) {
                    break;
                }
            }

            if (\count($batch) < self::BATCH) {
                break;
            }
        }

        // The current course's results first; the order by name holds inside each half.
        usort($found, static fn (array $a, array $b): int => $a[2] <=> $b[2]);

        return $this->withSnippets($found, $terms);
    }

    /**
     * The words of a query that are searched inside documents: the normalised ones of at least MIN_TERM characters, MAX_TERMS at most.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = [];
        foreach (TextNormalizer::terms($query) as $word) {
            if (mb_strlen($word) >= self::MIN_TERM && \count($words) < self::MAX_TERMS) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /** @return array{0: int|null, 1: bool} the cycle key of a submission (null for a plain document) and whether it is an earlier cycle than the current one */
    private function cycleOf(Document $document): array
    {
        $current = $this->cycles->currentCycle($document->getFolder());
        if ($current === null) {
            return [null, false];
        }

        // A submission that escaped stamping counts as the current cycle's, as in ActivityFolderCycleFilter::filter().
        $cycle = $document->getActivityCycleYear() ?? $current;

        return [$cycle, $cycle !== $current];
    }

    /**
     * Only now, for the few results that are going to be shown, is any text read.
     *
     * @param list<array{0: Document, 1: int|null, 2: bool}> $found
     * @param list<string>                                    $terms
     *
     * @return list<DocumentContentHit>
     */
    private function withSnippets(array $found, array $terms): array
    {
        $fileIds = [];
        foreach ($found as [$document]) {
            $file = $document->getActiveRevision()?->getFile();
            if ($file !== null) {
                $fileIds[] = $file->getId();
            }
        }
        $texts = $this->texts->findTexts($fileIds);

        $hits = [];
        foreach ($found as [$document, $cycle, $past]) {
            $file = $document->getActiveRevision()?->getFile();
            $text = $file === null ? null : ($texts[$file->getId()->toRfc4122()] ?? null);
            if ($text === null) {
                continue;
            }
            $snippet = SnippetBuilder::build($text, TextNormalizer::fold($text), $terms);
            if ($snippet !== null) {
                $hits[] = new DocumentContentHit($document, $snippet, $cycle, $past);
            }
        }

        return $hits;
    }
}
