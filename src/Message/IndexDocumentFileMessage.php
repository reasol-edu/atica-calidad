<?php

declare(strict_types=1);

namespace App\Message;

/** A new file was stored: read its text for the search (DocumentTextIndexer). Routed to the async transport. */
final readonly class IndexDocumentFileMessage
{
    /** @param string $fileId RFC 4122 id of the DocumentFile */
    public function __construct(public string $fileId) {}
}
