<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditChecklistTemplate;
use App\Entity\EducationalCentre;
use App\Repository\AuditChecklistTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The centre's library of audit checklists: loading the ISO 9001 ones that come with the
 * application (config/quality/iso9001_audit_checklists.json), editing them, and exporting and
 * importing them as JSON — in the same format — to share them between centres.
 */
final class AuditChecklistLibrary
{
    public const string FORMAT = 'atica-calidad-audit-checklists';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditChecklistTemplateRepository $templates,
        private readonly ActivityLogger $activityLogger,
        #[Autowire('%kernel.project_dir%/config/quality/iso9001_audit_checklists.json')]
        private readonly string $isoLibraryPath,
    ) {}

    /**
     * Adds the ISO 9001 checklists the centre doesn't have yet (by name).
     *
     * @return int how many were added
     */
    public function loadIso(EducationalCentre $centre): int
    {
        $added = $this->add($centre, $this->parse((string) file_get_contents($this->isoLibraryPath)), skipExisting: true);
        $this->activityLogger->record('audit_checklist.load_iso', ['count' => $added], $centre);

        return $added;
    }

    /**
     * Imports checklists exported from this or another centre; one named as an existing one gets
     * a number after its name.
     *
     * @return int how many were added
     *
     * @throws \InvalidArgumentException when it isn't a valid export
     */
    public function import(EducationalCentre $centre, string $json): int
    {
        $added = $this->add($centre, $this->parse($json), skipExisting: false);
        $this->activityLogger->record('audit_checklist.import', ['count' => $added], $centre);

        return $added;
    }

    /** @param list<AuditChecklistTemplate> $templates */
    public function export(array $templates): string
    {
        return (string) json_encode([
            'format'    => self::FORMAT,
            'version'   => 1,
            'templates' => array_map(static fn (AuditChecklistTemplate $t): array => [
                'name'   => $t->getName(),
                'clause' => $t->getClause(),
                'items'  => $t->getItems(),
            ], $templates),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** @param list<array{clause: ?string, question: string, guidance: ?string}> $items */
    public function save(AuditChecklistTemplate $template, string $name, ?string $clause, array $items): void
    {
        $template->setName(trim($name))->setClause(self::nullIfBlank($clause))->setItems($items);
        $this->em->persist($template);
        $this->em->flush();

        $this->activityLogger->record('audit_checklist.save', ['checklist' => $template->getName()], $template->getEducationalCentre());
    }

    public function delete(AuditChecklistTemplate $template): void
    {
        $name   = $template->getName();
        $centre = $template->getEducationalCentre();
        $this->em->remove($template);
        $this->em->flush();

        $this->activityLogger->record('audit_checklist.delete', ['checklist' => $name], $centre);
    }

    /**
     * @param list<array{name: string, clause: ?string, items: list<array{clause: ?string, question: string, guidance: ?string}>}> $parsed
     */
    private function add(EducationalCentre $centre, array $parsed, bool $skipExisting): int
    {
        $names = [];
        foreach ($this->templates->findByCentre($centre) as $existing) {
            $names[mb_strtolower($existing->getName())] = true;
        }

        $added = 0;
        foreach ($parsed as $t) {
            $name = $t['name'];
            if (isset($names[mb_strtolower($name)])) {
                if ($skipExisting) {
                    continue;
                }
                $n = 2;
                while (isset($names[mb_strtolower($name . ' (' . $n . ')')])) {
                    ++$n;
                }
                $name .= ' (' . $n . ')';
            }
            $names[mb_strtolower($name)] = true;
            $this->em->persist((new AuditChecklistTemplate($centre, $name, $t['clause']))->setItems($t['items']));
            ++$added;
        }
        $this->em->flush();

        return $added;
    }

    /**
     * @return list<array{name: string, clause: ?string, items: list<array{clause: ?string, question: string, guidance: ?string}>}>
     *
     * @throws \InvalidArgumentException
     */
    private function parse(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('not_json');
        }
        if (!\is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !\is_array($data['templates'] ?? null)) {
            throw new \InvalidArgumentException('not_a_library');
        }

        $parsed = [];
        foreach ($data['templates'] as $t) {
            if (!\is_array($t) || !\is_array($t['items'] ?? null)) {
                throw new \InvalidArgumentException('bad_template');
            }
            $name = \is_string($t['name'] ?? null) ? trim($t['name']) : '';
            if ($name === '') {
                throw new \InvalidArgumentException('bad_template');
            }
            $items = [];
            foreach ($t['items'] as $item) {
                if (!\is_array($item)) {
                    throw new \InvalidArgumentException('bad_item');
                }
                $question = \is_string($item['question'] ?? null) ? trim($item['question']) : '';
                if ($question === '') {
                    throw new \InvalidArgumentException('bad_item');
                }
                $items[] = [
                    'clause'   => self::nullIfBlank(\is_string($item['clause'] ?? null) ? $item['clause'] : null),
                    'question' => $question,
                    'guidance' => self::nullIfBlank(\is_string($item['guidance'] ?? null) ? $item['guidance'] : null),
                ];
            }
            $parsed[] = [
                'name'   => mb_substr($name, 0, 255),
                'clause' => self::nullIfBlank(\is_string($t['clause'] ?? null) ? mb_substr($t['clause'], 0, 20) : null),
                'items'  => $items,
            ];
        }

        return $parsed;
    }

    private static function nullIfBlank(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
