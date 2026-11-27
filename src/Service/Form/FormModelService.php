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
use App\Entity\FormUpdatePolicy;
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
        private readonly FormListOptions $lists,
        private readonly \App\Repository\FormModelRevisionRepository $modelRevisions,
        private readonly \App\Repository\FormModelRepository $models,
        private readonly \App\Repository\ListItemRepository $listItems,
    ) {}

    /**
     * What of the draft is still to send: the parts (`content`, `rules`, `approval`, `layout`) that differ from the last revision sent,
     * and the version of that revision (null when none was sent yet: then every part with something in it is to send).
     *
     * @return array{content: bool, rules: bool, approval: bool, layout: bool, base: ?int, any: bool}
     */
    public function changes(FormModel $model): array
    {
        $draft = $this->definitions->normalize($model->getDraftDefinition());
        $last  = $this->modelRevisions->findLatest($model);
        if ($last === null) {
            $parts = [
                'content'  => FormDefinition::listOf($draft['sections'] ?? []) !== [] || trim((string) $model->getCode()) !== '',
                'rules'    => FormDefinition::listOf($draft['rules'] ?? []) !== [],
                'approval' => FormDefinition::listOf($draft['approval'] ?? []) !== [],
                'layout'   => ($draft['layout'] ?? null) !== FormLayout::defaults(),
            ];

            return $parts + ['base' => null, 'any' => true];
        }

        $sent  = $this->definitions->normalize($last->getDefinition());
        $parts = [
            'content'  => $draft['sections'] !== $sent['sections'] || trim((string) $model->getCode()) !== trim((string) $last->getCode()),
            'rules'    => $draft['rules'] !== $sent['rules'],
            'approval' => $draft['approval'] !== $sent['approval'],
            'layout'   => $draft['layout'] !== $sent['layout'],
        ];

        return $parts + ['base' => $last->getRevision()->getVersion(), 'any' => in_array(true, $parts, true)];
    }

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
     * the approval chain that do not exist in this centre, and what its tables of deliveries cannot read. $model is the model the
     * definition is of (for a table of deliveries that would read, through other models, its own deliveries).
     *
     * @param array<string, mixed> $definition
     *
     * @return list<array{code: string, params: array<string, string>}>
     */
    public function problems(array $definition, EducationalCentre $centre, ?FormModel $model = null): array
    {
        $problems = $this->definitions->problems($definition);
        foreach (FormDefinition::listOf($definition['approval'] ?? []) as $step) {
            $profile = FormDefinition::str($step['profile'] ?? '');
            if ($profile !== '' && $this->profiles->findByIdAndCentre($profile, $centre) === null) {
                $problems[] = ['code' => 'approval_profile_missing', 'params' => ['%name%' => FormDefinition::str($step['name'] ?? '')]];
            }
        }

        foreach ($this->lists->emptySources($definition, $centre) as $label) {
            $problems[] = ['code' => 'choice_list_empty', 'params' => ['%label%' => $label]];
        }

        return [...$problems, ...$this->deliveredProblems($definition, $centre, $model)];
    }

    /**
     * What stops a table of deliveries from working: no model chosen, a model that is gone (or has no revision in force), no column
     * of the delivery, a column that reads a field the model does not have now (or with a type that cannot go into it), a table inside a
     * repeating section (one per copy: not supported), and two models that read each other's deliveries.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<array{code: string, params: array<string, string>}>
     */
    private function deliveredProblems(array $definition, EducationalCentre $centre, ?FormModel $model): array
    {
        $problems = [];
        $inside   = array_map(static fn (array $b): string => FormDefinition::str($b['key'] ?? ''), FormDeliveredRows::tables($definition));
        foreach (FormDefinition::blocks($definition) as ['block' => $block]) {
            if (($block['type'] ?? '') !== 'table' || ($block['mode'] ?? '') !== 'delivered') {
                continue;
            }
            $label = FormDefinition::str($block['label'] ?? '');
            if (!in_array(FormDefinition::str($block['key'] ?? ''), $inside, true)) {
                $problems[] = ['code' => 'delivered_in_repeat', 'params' => ['%label%' => $label]];
                continue;
            }
            $sourceId = FormDefinition::str($block['sourceModel'] ?? '');
            if ($sourceId === '') {
                $problems[] = ['code' => 'delivered_without_model', 'params' => ['%label%' => $label]];
                continue;
            }
            $source = $this->models->findByIdAndCentre($sourceId, $centre);
            $force  = $source === null ? null : $this->modelRevisions->findInForce($source);
            if ($source === null || $force === null) {
                $problems[] = ['code' => 'delivered_model_gone', 'params' => ['%label%' => $label]];
                continue;
            }
            $columns = FormDeliveredRows::sourceColumns($block);
            if ($columns === []) {
                $problems[] = ['code' => 'delivered_without_columns', 'params' => ['%label%' => $label]];
            }
            // Columns pinned to a value of a list (one per evaluation): all of them, and all of one list — the rows are the deliveries
            // without that list, so a column of «each delivery» or of another list has nothing to read.
            $pinned = FormDeliveredRows::pinnedValues($block);
            if ($pinned !== []) {
                if (\count($pinned) < \count($columns)) {
                    $problems[] = ['code' => 'delivered_values_mixed', 'params' => ['%label%' => $label]];
                }
                $index = $this->listItems->indexByCentre($centre);
                $roots = [];
                foreach ($pinned as $valueId) {
                    $value = $index[$valueId] ?? null;
                    $roots[$value === null ? '?' : $value->getRoot()->getId()->toRfc4122()] = true;
                }
                if (isset($roots['?'])) {
                    $problems[] = ['code' => 'delivered_value_gone', 'params' => ['%label%' => $label]];
                } elseif (\count($roots) > 1) {
                    $problems[] = ['code' => 'delivered_values_lists', 'params' => ['%label%' => $label]];
                }
            }
            $fields = FormDeliveredRows::namedFields($force->getDefinition());
            foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
                $name = FormDefinition::str($column['sourceField'] ?? '');
                if ($name === '') {
                    continue;
                }
                $field = $fields[$name] ?? null;
                if ($field === null || FormDeliveredRows::columnTypeFor(FormDefinition::str($field['fieldType'] ?? '')) !== ($column['fieldType'] ?? null)) {
                    $problems[] = ['code' => 'delivered_field_gone', 'params' => ['%column%' => FormDefinition::str($column['label'] ?? ''), '%field%' => $name, '%model%' => $source->getDocument()->getName()]];
                }
            }
            if ($model !== null && $this->readsDeliveriesOf($source, $model, $centre, [])) {
                $problems[] = ['code' => 'delivered_cycle', 'params' => ['%model%' => $source->getDocument()->getName()]];
            }
        }

        return $problems;
    }

    /**
     * Whether the revision in force of $model has a table of deliveries of $target, directly or through the models it reads.
     *
     * @param array<string, true> $seen
     */
    private function readsDeliveriesOf(FormModel $model, FormModel $target, EducationalCentre $centre, array $seen): bool
    {
        $id = $model->getId()->toRfc4122();
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
        foreach (FormDeliveredRows::tables($this->modelRevisions->findInForce($model)?->getDefinition() ?? []) as $block) {
            $next = FormDefinition::str($block['sourceModel'] ?? '');
            if ($next === $target->getId()->toRfc4122()) {
                return true;
            }
            $nextModel = $next === '' ? null : $this->models->findByIdAndCentre($next, $centre);
            if ($nextModel !== null && $this->readsDeliveriesOf($nextModel, $target, $centre, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Freezes the draft as the next revision of the document and sends it to review. `$notify` false keeps the reviewers' e-mail
     * from going out: the revision is about to be approved by whoever sends it (FormModelController::send(), «Aprobar nueva revisión»).
     *
     * @throws FormException `error.model_problems` (with what to fix) when the draft cannot work yet
     */
    public function sendToReview(FormModel $model, Teacher $by, ?string $description, FormUpdatePolicy $policy = FormUpdatePolicy::Optional, ?\DateTimeImmutable $deadline = null, bool $notify = true): DocumentRevision
    {
        $definition = $this->definitions->normalize($model->getDraftDefinition());
        $document   = $model->getDocument();
        $folder     = $document->getFolder();
        $centre     = $folder->getEducationalCentre();

        $problems = $this->problems($definition, $centre, $model);
        if ($problems !== []) {
            throw new FormException('error.model_problems', $problems);
        }

        // The blank PDF is what the tree downloads, the search reads and the reviewers open.
        $version = $document->getNextVersion();
        // (With the rows taken from lists: every item, since nobody delivers it.)
        $blank   = $this->lists->expand($definition, $centre);
        $content = $this->pdf->build($blank, $this->data->sample($blank), $centre, $document->getName(), $model->getCode(), $version, true, ['centre' => $centre->getName(), 'activity' => $document->getName()], null, null, null, $folder->requiresReview());
        $file    = $this->creation->storeFile($content, 'application/pdf', $document->getName() . '.pdf');

        $revision = $this->creation->addRevision($document, $file, $by, $description);
        $this->em->persist(new FormModelRevision($model, $revision, $definition, $policy, $deadline));
        $model->saveDraft($definition, $by);
        $this->em->flush();

        $this->logger->record('form_model.send', ['document' => $document->getName(), 'version' => $revision->getVersion()], $centre);
        if ($notify && $revision->isPendingReview()) {
            $this->notifier->notifyReviewers($revision);
        }

        return $revision;
    }

    /**
     * Makes the blank PDF of an approved model revision again: while it was pending the PDF went out without an approval date
     * ({fecha_aprobacion}), and now it has one. Does nothing for a revision that is not of a model (a filled form, a plain upload).
     * The caller flushes.
     */
    public function refreshBlankPdf(DocumentRevision $revision): void
    {
        $modelRevision = $this->modelRevisions->findByRevision($revision);
        if ($modelRevision === null || !$revision->isApproved()) {
            return;
        }

        $document = $revision->getDocument();
        $centre   = $document->getFolder()->getEducationalCentre();
        // Blank: the rows taken from a list are all its items (nobody delivers it yet).
        $definition = $this->lists->expand($modelRevision->getDefinition(), $centre);
        $content    = $this->pdf->buildForRevision($modelRevision, $definition, $this->data->sample($definition), $centre, $document->getName(), true, ['centre' => $centre->getName(), 'activity' => $document->getName()]);

        $revision->replaceFile($this->creation->storeFile($content, 'application/pdf', $document->getName() . '.pdf'));
    }
}
