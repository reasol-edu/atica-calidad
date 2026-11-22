<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\Teacher;

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

        return ['format' => self::FORMAT, 'version' => 1, 'name' => $model->getDocument()->getName(), 'code' => $model->getCode(), 'definition' => $definition];
    }

    /** The draft of a model as a DOCX or ODT, blank (what it will look like to fill in). */
    public function exportOffice(FormModel $model, string $format, string $centreName): string
    {
        $definition = $this->definitions->normalize($model->getDraftDefinition());

        return $this->writer->build(
            $format,
            $model->getDocument()->getName(),
            $model->getCode(),
            $centreName,
            $this->resolver->resolve($definition, $this->data->sample($definition), ['centre' => $centreName, 'activity' => $model->getDocument()->getName()]),
        );
    }

    /**
     * Creates a model in a folder from an exported one.
     *
     * @param array<mixed> $payload the decoded JSON
     *
     * @throws FormException `error.import_file` when it is not an exported model
     */
    public function import(array $payload, Folder $folder, ?string $name, ?Teacher $by): FormModel
    {
        $definition = FormDefinition::map($payload['definition'] ?? null);
        if (($payload['format'] ?? null) !== self::FORMAT || $definition === null) {
            throw new FormException('error.import_file');
        }
        unset($definition['approval']);
        $title = $name !== null && trim($name) !== '' ? trim($name) : (\is_string($payload['name'] ?? null) ? $payload['name'] : 'Modelo');

        return $this->create($folder, $title, \is_string($payload['code'] ?? null) ? $payload['code'] : null, $definition, $by);
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
