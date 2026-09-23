<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentReadAcknowledgement;
use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Model\ReadAcknowledgementStatus;
use App\Repository\DocumentReadAcknowledgementRepository;
use App\Repository\DocumentRepository;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read acknowledgement ("acuse de lectura") of the documents in a folder that requires it
 * (Folder::requiresReadAcknowledgement()). Who has to read a document: every active teacher of the
 * centre's active academic year who can reach it in the tree (DocumentTreeAccessChecker::
 * canViewDocument()) — so restricting the folder or its sections to some profiles restricts who
 * has to read it too — except whoever uploaded the version in force. What they acknowledge is
 * that version: a new one in force has to be read again.
 */
final class ReadAcknowledgementService implements ResetInterface
{
    /**
     * Expected readers by folder id, for this request only: reset() empties it between requests,
     * which matters in FrankenPHP's worker mode, where the service outlives the request.
     *
     * @var array<string, list<Teacher>>
     */
    private array $readersByFolder = [];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentReadAcknowledgementRepository $acknowledgements,
        private readonly TeacherRepository $teachers,
        private readonly DocumentTreeAccessChecker $access,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    public function reset(): void
    {
        $this->readersByFolder = [];
    }

    /** Whether $teacher has to acknowledge $document's version in force. */
    public function mustRead(Teacher $teacher, Document $document): bool
    {
        $revision = $this->revisionToRead($document);

        return $revision !== null
            && $revision->getUploadedBy() !== $teacher
            && \in_array($teacher, $this->readersOf($document->getFolder()), true);
    }

    /** $teacher's acknowledgement of $document's version in force, if any. */
    public function acknowledgementOf(Teacher $teacher, Document $document): ?DocumentReadAcknowledgement
    {
        $revision = $this->revisionToRead($document);

        return $revision === null ? null : $this->acknowledgements->findOneByRevisionAndTeacher($revision, $teacher);
    }

    /**
     * Records that $teacher has read $document's version in force. Idempotent.
     *
     * @throws \LogicException when $teacher doesn't have to read it
     */
    public function acknowledge(Teacher $teacher, Document $document): DocumentReadAcknowledgement
    {
        $revision = $this->revisionToRead($document);
        if ($revision === null || !$this->mustRead($teacher, $document)) {
            throw new \LogicException('This teacher does not have to acknowledge this document.');
        }

        $existing = $this->acknowledgements->findOneByRevisionAndTeacher($revision, $teacher);
        if ($existing !== null) {
            return $existing;
        }

        $acknowledgement = new DocumentReadAcknowledgement($revision, $teacher, $this->clock->now());
        $this->em->persist($acknowledgement);
        $this->em->flush();

        return $acknowledgement;
    }

    /**
     * The centre's documents $teacher still has to acknowledge, in tree order.
     *
     * @return list<Document>
     */
    public function pendingFor(Teacher $teacher, EducationalCentre $centre): array
    {
        $candidates = array_values(array_filter(
            $this->documents->findRequiringReadAcknowledgementByCentre($centre),
            fn (Document $d): bool => $this->mustRead($teacher, $d),
        ));

        $acknowledged = $this->acknowledgements->findByTeacherIndexedByRevision(
            $teacher,
            array_values(array_filter(array_map($this->revisionToRead(...), $candidates))),
        );

        return array_values(array_filter(
            $candidates,
            static fn (Document $d): bool => !isset($acknowledged[$d->getActiveRevision()?->getId()->toRfc4122() ?? '']),
        ));
    }

    /**
     * Who has read, and who still has to read, each of $documents' versions in force — for the
     * folder's managers and the "Acuses de lectura" report. Documents that need no acknowledgement
     * are left out.
     *
     * @param list<Document> $documents
     *
     * @return array<string, ReadAcknowledgementStatus> keyed by document id (RFC 4122)
     */
    public function statusOf(array $documents): array
    {
        $documents = array_values(array_filter($documents, fn (Document $d): bool => $this->revisionToRead($d) !== null));
        if ($documents === []) {
            return [];
        }

        /** @var array<string, array<string, DocumentReadAcknowledgement>> $byRevision revision id → teacher id → ack */
        $byRevision = [];
        foreach ($this->acknowledgements->findByRevisions(array_values(array_filter(array_map($this->revisionToRead(...), $documents)))) as $ack) {
            $byRevision[$ack->getRevision()->getId()->toRfc4122()][$ack->getTeacher()->getId()->toRfc4122()] = $ack;
        }

        $statuses = [];
        foreach ($documents as $document) {
            $revision = $this->revisionToRead($document);
            if ($revision === null) {
                continue;
            }
            $acks    = $byRevision[$revision->getId()->toRfc4122()] ?? [];
            $read    = [];
            $pending = [];
            foreach ($this->readersOf($document->getFolder()) as $teacher) {
                if ($teacher === $revision->getUploadedBy()) {
                    continue;
                }
                $ack = $acks[$teacher->getId()->toRfc4122()] ?? null;
                if ($ack !== null) {
                    $read[] = $ack;
                } else {
                    $pending[] = $teacher;
                }
            }
            $statuses[$document->getId()->toRfc4122()] = new ReadAcknowledgementStatus($document, $read, $pending);
        }

        return $statuses;
    }

    /** The version in force of a document whose folder requires acknowledgement; null otherwise. */
    private function revisionToRead(Document $document): ?DocumentRevision
    {
        return $document->getFolder()->requiresReadAcknowledgement() && !$document->getFolder()->isObsolete()
            ? $document->getActiveRevision()
            : null;
    }

    /**
     * Active teachers of the centre's active year who can reach $folder, by name.
     *
     * @return list<Teacher>
     */
    private function readersOf(Folder $folder): array
    {
        $key = $folder->getId()->toRfc4122();
        if (isset($this->readersByFolder[$key])) {
            return $this->readersByFolder[$key];
        }

        $year = $folder->getEducationalCentre()->getActiveAcademicYear();
        if ($year === null) {
            return $this->readersByFolder[$key] = [];
        }

        return $this->readersByFolder[$key] = array_values(array_filter(
            $this->teachers->findByAcademicYearOrderedByName($year),
            fn (Teacher $t): bool => $t->isActive() && $this->access->canReachFolder($t, $folder),
        ));
    }
}
