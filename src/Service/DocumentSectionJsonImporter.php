<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Repository\DocumentSectionRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces a centre's whole document-section tree (Árbol documental) from a previously exported
 * JSON payload (see DocumentSectionJsonExporter). This is a full replace, not a merge: every
 * existing section of the centre is deleted first (cascading onto its profile restrictions).
 * Profile/subperfil references are resolved by name against the centre's *current* specific
 * profiles and list items — untouched by this import — so a name that no longer exists is simply
 * skipped and counted, without failing the rest of the import.
 */
class DocumentSectionJsonImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentSectionRepository $sections,
        private readonly SpecificProfileRepository $profiles,
        private readonly ListItemRepository $listItems,
    ) {}

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array{sections: int, skippedProfiles: int}
     *
     * @throws \InvalidArgumentException malformed JSON payload
     */
    public function import(array $data, EducationalCentre $centre): array
    {
        $sections = $data['sections'] ?? null;
        if (($data['type'] ?? null) !== 'document_sections' || !is_array($sections)) {
            throw new \InvalidArgumentException('Not a valid document-sections export.');
        }
        $this->validateLevel($sections);

        $existing      = $this->sections->findAllByCentre($centre);
        $profilesByName = [];
        foreach ($this->profiles->findByCentre($centre) as $profile) {
            $profilesByName[$profile->getName()] ??= $profile;
        }

        $counts = ['sections' => 0, 'skippedProfiles' => 0];

        $this->em->getConnection()->transactional(function () use ($existing, $centre, $sections, $profilesByName, &$counts): void {
            foreach ($existing as $section) {
                $this->em->remove($section);
            }
            $this->em->flush();

            $this->createLevel($sections, $centre, null, $profilesByName, $counts);
            $this->em->flush();
        });

        return $counts;
    }

    private function validateLevel(mixed $sections): void
    {
        if (!is_array($sections)) {
            throw new \InvalidArgumentException('Expected an array of sections.');
        }

        foreach ($sections as $node) {
            if (!is_array($node)) {
                throw new \InvalidArgumentException('Each section must be an object.');
            }
            if (!is_string($node['name'] ?? null) || trim($node['name']) === '') {
                throw new \InvalidArgumentException('Each section needs a non-empty "name".');
            }
            $profiles = $node['profiles'] ?? null;
            if (!is_array($profiles)) {
                throw new \InvalidArgumentException('"profiles" must be an array.');
            }
            foreach ($profiles as $restriction) {
                if (!is_array($restriction) || !is_string($restriction['profile'] ?? null)) {
                    throw new \InvalidArgumentException('Each profile restriction needs a "profile" name.');
                }
                $path = $restriction['listItemPath'] ?? null;
                if ($path !== null && (!is_array($path) || array_filter($path, static fn (mixed $p): bool => !is_string($p)) !== [])) {
                    throw new \InvalidArgumentException('"listItemPath" must be null or an array of strings.');
                }
            }
            $this->validateLevel($node['children'] ?? null);
        }
    }

    /**
     * @param array<mixed, mixed>                        $nodes
     * @param array<string, SpecificProfile>              $profilesByName
     * @param array{sections: int, skippedProfiles: int}  $counts
     */
    private function createLevel(array $nodes, EducationalCentre $centre, ?DocumentSection $parent, array $profilesByName, array &$counts): void
    {
        foreach (array_values($nodes) as $position => $node) {
            $name = is_array($node) ? $node['name'] ?? null : null;
            if (!is_string($name)) {
                continue;
            }

            $section = (new DocumentSection())
                ->setEducationalCentre($centre)
                ->setName(trim($name))
                ->setPosition($position);
            $section->setParent($parent);

            $restrictions = is_array($node['profiles'] ?? null) ? $node['profiles'] : [];
            foreach ($restrictions as $restriction) {
                if (!is_array($restriction) || !is_string($restriction['profile'] ?? null)) {
                    continue;
                }

                $profile = $profilesByName[$restriction['profile']] ?? null;
                if ($profile === null) {
                    ++$counts['skippedProfiles'];

                    continue;
                }

                $path = $restriction['listItemPath'] ?? null;
                if ($path === null) {
                    $section->addProfileRestriction($profile, null);

                    continue;
                }

                $pathNames = is_array($path)
                    ? array_values(array_filter($path, static fn (mixed $p): bool => is_string($p)))
                    : [];
                $listItem = $this->resolveListItemPath($centre, $pathNames);
                if ($listItem === null) {
                    ++$counts['skippedProfiles'];

                    continue;
                }

                $section->addProfileRestriction($profile, $listItem);
            }

            $this->em->persist($section);
            ++$counts['sections'];

            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $this->createLevel($children, $centre, $section, $profilesByName, $counts);
        }
    }

    /** @param array<int, string> $path root-first names */
    private function resolveListItemPath(EducationalCentre $centre, array $path): ?ListItem
    {
        $siblings = $this->listItems->findRootsByCentre($centre);
        $current  = null;

        foreach ($path as $name) {
            $match = null;
            foreach ($siblings as $sibling) {
                if ($sibling->getName() === $name) {
                    $match = $sibling;

                    break;
                }
            }
            if ($match === null) {
                return null;
            }

            $current  = $match;
            $siblings = $this->listItems->findChildrenByParent($match);
        }

        return $current;
    }
}
