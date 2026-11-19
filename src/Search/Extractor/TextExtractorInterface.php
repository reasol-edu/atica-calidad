<?php

declare(strict_types=1);

namespace App\Search\Extractor;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Reads the text out of one kind of file for the search index. Everything that implements this is picked up by
 * DocumentTextIndexer, which asks each in turn whether it can read a file. Implementations run in the worker, on files anyone
 * with upload rights may have made: they must read with a ceiling on memory and never trust a size or structure the file
 * announces about itself.
 */
#[AutoconfigureTag('app.text_extractor')]
interface TextExtractorInterface
{
    /** Whether this extractor reads a file with that MIME type (as stored) and name. */
    public function supports(string $mimeType, string $filename): bool;

    /** Largest file, in bytes, this extractor is willing to open; a bigger one is recorded as too large instead. */
    public function maxFileBytes(): int;

    /**
     * The text of the file at $path, in any whitespace layout (the indexer cleans it) and as long as the extractor wishes,
     * though it may stop once it has $maxChars characters.
     *
     * @throws ExtractionException the file cannot be read: damaged, encrypted, not what its name says
     */
    public function extract(string $path, int $maxChars): string;
}
