<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\Teacher;
use App\Repository\FormModelRepository;

/**
 * Models in and out as files: the JSON library ("take this model to another centre"), the copy of a model within the centre and the
 * first draft of a model from a DOCX/ODT. What came in is always a new DRAFT of a new model, cleaned by the same normalizer as
 * anything typed in the editor; nothing reaches the centre's documents until it is sent to review like any other model.
 */
final class FormModelPorter
{
    public const string FORMAT = 'atica-modelo';

    public function __construct(
        private readonly FormModelService $service,
        private readonly FormDefinitionNormalizer $definitions,
        private readonly FormOfficeReader $office,
        private readonly FormOfficeWriter $writer,
        private readonly FormResolver $resolver,
        private readonly FormDataNormalizer $data,
        private readonly FormListOptions $lists,
        private readonly FormLayoutResolver $layouts,
        private readonly FormModelRepository $models,
    ) {}

    /**
     * The draft of a model as portable JSON. The approval chain names profiles of THIS centre, so it is not carried along.
     *
     * @return array<string, mixed>
     */
    public function export(FormModel $model): array
    {
        $definition = $this->definitions->normalize($model->getDraftDefinition());
        unset($definition['approval']);
        $centre     = $model->getDocument()->getFolder()->getEducationalCentre();
        // A list is an item of THIS centre's Responsabilidades: it travels as the names that lead to it.
        $definition = $this->lists->toPaths($definition, $centre);
        // The model whose deliveries a table reads is one of THIS centre's: it travels as its title.
        $titles     = [];
        foreach ($this->models->findByCentre($centre) as $other) {
            $titles[$other->getId()->toRfc4122()] = $other->getDocument()->getName();
        }
        $definition = $this->sourceModels($definition, static fn (string $id): string => $titles[$id] ?? '');

        return ['format' => self::FORMAT, 'version' => 1, 'name' => $model->getDocument()->getName(), 'code' => $model->getCode(), 'definition' => $definition];
    }

    /**
     * $definition with the `sourceModel` of every table of deliveries (in any section, at any depth) replaced by $map($sourceModel).
     *
     * @param array<string, mixed>     $definition
     * @param callable(string): string $map
     *
     * @return array<string, mixed>
     */
    private function sourceModels(array $definition, callable $map): array
    {
        $walk = static function (array $sections) use (&$walk, $map): array {
            foreach ($sections as $i => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
                foreach ($blocks as $b => $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'table' && ($block['mode'] ?? '') === 'delivered') {
                        $block['sourceModel'] = $map(FormDefinition::str($block['sourceModel'] ?? ''));
                        $blocks[$b]           = $block;
                    }
                }
                $section['blocks'] = $blocks;
                $section['children'] = $walk(is_array($section['children'] ?? null) ? $section['children'] : []);
                $sections[$i]        = $section;
            }

            return $sections;
        };
        $definition['sections'] = $walk(is_array($definition['sections'] ?? null) ? $definition['sections'] : []);

        return $definition;
    }

    /** The draft of a model as a DOCX or ODT, blank (what it will look like to fill in). */
    public function exportOffice(FormModel $model, string $format, EducationalCentre $centre): string
    {
        // Blank: the rows taken from a list are all its items.
        $definition = $this->lists->expand($this->definitions->normalize($model->getDraftDefinition()), $centre);
        $document   = $model->getDocument();
        $layout     = $this->layouts->resolve($definition, $centre, $this->layouts->values($centre, $document->getName(), $model->getCode(), $document->getNextVersion(), new \DateTimeImmutable()));

        return $this->writer->build(
            $format,
            $document->getName(),
            $this->resolver->resolve($definition, $this->data->sample($definition), ['centre' => $centre->getName(), 'activity' => $document->getName()]),
            $layout,
        );
    }

    /**
     * Creates a model in a folder from an exported one. The fields that took their options from a list are linked to the list of the
     * same name in this centre; the ones whose list is not here are left without one and reported, to be linked in the editor.
     *
     * @param array<mixed> $payload the decoded JSON
     *
     * A table of deliveries reads the model of the same title in this centre; when there is none it is left without one (the editor
     * says so) and its label reported.
     *
     * @return array{model: FormModel, unlinked: list<string>, unsourced: list<string>} the new model, the labels of the fields left
     *                                                                                 without a list and of the tables without a model
     *
     * @throws FormException `error.import_file` when it is not an exported model
     */
    public function import(array $payload, Folder $folder, ?string $name, ?Teacher $by): array
    {
        $definition = FormDefinition::map($payload['definition'] ?? null);
        if (($payload['format'] ?? null) !== self::FORMAT || $definition === null) {
            throw new FormException('error.import_file');
        }
        unset($definition['approval']);
        $centre                  = $folder->getEducationalCentre();
        [$definition, $unlinked] = $this->lists->fromPaths($definition, $centre);
        $title                   = $name !== null && trim($name) !== '' ? trim($name) : (\is_string($payload['name'] ?? null) ? $payload['name'] : 'Modelo');

        $ids = [];
        foreach ($this->models->findByCentre($centre) as $other) {
            $ids[mb_strtolower(trim($other->getDocument()->getName()))] ??= $other->getId()->toRfc4122();
        }
        $definition = $this->sourceModels($definition, static fn (string $title): string => $ids[mb_strtolower(trim($title))] ?? '');
        $unsourced  = [];
        foreach (FormDeliveredRows::tables($definition) as $block) {
            if (FormDefinition::str($block['sourceModel'] ?? '') === '') {
                $unsourced[] = FormDefinition::str($block['label'] ?? '');
            }
        }

        return ['model' => $this->create($folder, $title, \is_string($payload['code'] ?? null) ? $payload['code'] : null, $definition, $by), 'unlinked' => $unlinked, 'unsourced' => $unsourced];
    }

    /** A copy of a model (its draft) in the same or another folder, as a new draft. */
    public function duplicate(FormModel $model, Folder $folder, string $name, ?Teacher $by): FormModel
    {
        $definition = $this->definitions->normalize($model->getDraftDefinition());
        unset($definition['approval']);

        return $this->create($folder, $name, $model->getCode(), $definition, $by);
    }

    /**
     * Creates a model from the content of a DOCX or ODT file (headings, paragraphs and tables).
     *
     * @throws FormException `error.import_file`
     */
    public function importOffice(string $path, string $format, Folder $folder, string $name, ?Teacher $by): FormModel
    {
        return $this->create($folder, $name, null, $this->office->read($path, $format), $by);
    }

    /** @param array<string, mixed> $definition */
    private function create(Folder $folder, string $name, ?string $code, array $definition, ?Teacher $by): FormModel
    {
        $model = $this->service->create($folder, mb_substr(trim($name) === '' ? 'Modelo' : trim($name), 0, 255), $code === null ? null : mb_substr($code, 0, 40));

        return $this->service->saveDraft($model, $definition, $model->getDraftVersion(), $by);
    }
}
