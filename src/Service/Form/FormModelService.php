<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Repository\SpecificProfileRepository;
use App\Entity\DocumentRevision;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\FormModelRevision;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Service\ActivityLogger;
use App\Service\DocumentCreationService;
use App\Service\DocumentReviewNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The life of a model: created as a document of the tree with an empty draft, edited, and sent to review as a new revision.
 * A revision of a model is a normal DocumentRevision — pending when the folder requires review, approved by the folder's review
 * profile with the usual screens — whose file is the blank PDF and whose frozen definition is its FormModelRevision.
 */
final class FormModelService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentRepository $documents,
        private readonly DocumentCreationService $creation,
        private readonly FormDefinitionNormalizer $definitions,
        private readonly FormDataNormalizer $data,
        private readonly FormPdfBuilder $pdf,
        private readonly DocumentReviewNotifier $notifier,
        private readonly ActivityLogger $logger,
        private readonly SpecificProfileRepository $profiles,
    ) {}

    /** Persists and flushes the model and its document (which has no revision until the first one is sent to review). */
    public function create(Folder $folder, string $name, ?string $code): FormModel
    {
        $document = new Document($folder, $name);
        $document->setPosition($this->documents->nextPositionInFolder($folder));
        $this->em->persist($document);

        $model = new FormModel($document, FormDefinition::blank());
        $model->setCode($code);
        $this->em->persist($model);
        $this->em->flush();

        return $model;
    }

    /**
     * Saves the draft (cleaned) when $baseVersion is the version the editor started from.
     *
     * @param array<string, mixed> $raw
     *
     * @throws FormException `error.stale` when somebody saved in between
     */
    public function saveDraft(FormModel $model, array $raw, int $baseVersion, ?Teacher $by): FormModel
    {
        if ($baseVersion !== $model->getDraftVersion()) {
            throw new FormException('error.stale');
        }
        $model->saveDraft($this->definitions->normalize($raw), $by);
        $this->em->flush();

        return $model;
    }

    /**
     * What stops a (normalized) definition from being sent to review: what the definition itself says is wrong, and the profiles of
     * the approval chain that do not exist in this centre.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<array{code: string, params: array<string, string>}>
     */
    public function problems(array $definition, EducationalCentre $centre): array
    {
        $problems = $this->definitions->problems($definition);
        foreach (FormDefinition::listOf($definition['approval'] ?? []) as $step) {
            $profile = FormDefinition::str($step['profile'] ?? '');
            if ($profile !== '' && $this->profiles->findByIdAndCentre($profile, $centre) === null) {
                $problems[] = ['code' => 'approval_profile_missing', 'params' => ['%name%' => FormDefinition::str($step['name'] ?? '')]];
            }
        }

        return $problems;
    }

    /**
     * Freezes the draft as the next revision of the document and sends it to review.
     *
     * @throws FormException `error.model_problems` (with what to fix) when the draft cannot work yet
     */
    public function sendToReview(FormModel $model, Teacher $by, ?string $description): DocumentRevision
    {
        $definition = $this->definitions->normalize($model->getDraftDefinition());
        $document   = $model->getDocument();
        $folder     = $document->getFolder();
        $centre     = $folder->getEducationalCentre();

        $problems = $this->problems($definition, $centre);
        if ($problems !== []) {
            throw new FormException('error.model_problems', $problems);
        }

        // The blank PDF is what the tree downloads, the search reads and the reviewers open.
        $version = $document->getNextVersion();
        $content = $this->pdf->build($definition, $this->data->sample($definition), $centre, $document->getName(), $model->getCode(), $version, true, ['centre' => $centre->getName(), 'activity' => $document->getName()]);
        $file    = $this->creation->storeFile($content, 'application/pdf', $document->getName() . '.pdf');

        $revision = $this->creation->addRevision($document, $file, $by, $description);
        $this->em->persist(new FormModelRevision($model, $revision, $definition));
        $model->saveDraft($definition, $by);
        $this->em->flush();

        $this->logger->record('form_model.send', ['document' => $document->getName(), 'version' => $revision->getVersion()], $centre);
        if ($revision->isPendingReview()) {
            $this->notifier->notifyReviewers($revision);
        }

        return $revision;
    }
}
