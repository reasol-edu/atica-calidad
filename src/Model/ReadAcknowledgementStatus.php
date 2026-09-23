<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Document;
use App\Entity\DocumentReadAcknowledgement;
use App\Entity\Teacher;

/** Who has read, and who still has to read, a document's version in force (ReadAcknowledgementService::statusOf()). */
final readonly class ReadAcknowledgementStatus
{
    /**
     * @param list<DocumentReadAcknowledgement> $read    by name
     * @param list<Teacher>                     $pending by name
     */
    public function __construct(
        public Document $document,
        public array $read,
        public array $pending,
    ) {}

    public function readCount(): int
    {
        return \count($this->read);
    }

    public function total(): int
    {
        return \count($this->read) + \count($this->pending);
    }

    public function isComplete(): bool
    {
        return $this->pending === [];
    }
}
