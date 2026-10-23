<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Repository\ListItemRepository;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\DocumentTreeAccessChecker;
use App\Service\ProfileAssignmentRowBuilder;
use App\Tests\Integration\RepositoryTestCase;

/**
 * An element of a list can be associated with a subprofile that lives in the very tree it belongs
 * to (the profile's list IS the list being associated): every walk over the tree must still end,
 * and deleting a branch must not trip over associations pointing into it. Nothing follows an
 * association to another association — each is one hop, from an element to a subprofile — so no
 * chain can loop; these tests pin that down for the awkward shapes.
 */
final class ProfileAssociationSelfReferenceTest extends RepositoryTestCase
{
    /**
     * Departamento > (FP > Informática, Sanidad) and (ESO/Bach > Matemáticas), with the profile
     * Jefatura on "Departamento" — and FP, in that same tree, associated with Jefatura's own
     * subprofile FP.
     *
     * @return array<string, mixed>
     */
    private function selfReferentialTree(): array
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $dept   = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $fp     = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($dept);
        $infor  = (new ListItem())->setEducationalCentre($centre)->setName('Informática')->setParent($fp);
        $sani   = (new ListItem())->setEducationalCentre($centre)->setName('Sanidad')->setParent($fp);
        $eso    = (new ListItem())->setEducationalCentre($centre)->setName('ESO/Bach')->setParent($dept);
        $mates  = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas')->setParent($eso);
        $jefe   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($dept);

        $fp->addProfileAssociation($jefe, $fp);       // an element associated with a subprofile of its own tree: itself
        $dept->addProfileAssociation($jefe, $dept);   // the root, with the subprofile that is the root

        $this->persist($centre, $dept, $fp, $infor, $sani, $eso, $mates, $jefe);

        return compact('centre', 'dept', 'fp', 'infor', 'sani', 'eso', 'mates', 'jefe');
    }

    public function testEveryWalkOverASelfReferentialTreeEnds(): void
    {
        ['centre' => $centre, 'dept' => $dept, 'fp' => $fp, 'infor' => $infor, 'mates' => $mates, 'jefe' => $jefe] = $this->selfReferentialTree();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);

        // The inherited associations of a leaf come from the closest associated ancestor...
        self::assertSame($fp, $infor->getAssociationSource());
        // ...and an item reaching its own root through them still stops there.
        self::assertSame($dept, $mates->getAssociationSource());
        self::assertCount(2, $items->findLeafDescendants($fp));
        self::assertCount(3, $items->findLeafDescendants($dept));
        self::assertSame([$infor->getId()->toRfc4122(), $fp->getId()->toRfc4122(), $dept->getId()->toRfc4122()], $items->findSelfAndAncestorIds($infor));

        /** @var ProfileAssignmentRowBuilder $rows */
        $rows = self::getContainer()->get(ProfileAssignmentRowBuilder::class);
        self::assertNotEmpty($rows->buildActiveRowsForAssociation($centre));
        self::assertNotEmpty($rows->buildActiveRowsForPicker($centre));

        $teacher = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('docente');
        $this->persist($teacher, new SpecificProfileAssignment($jefe, $infor, $teacher));
        /** @var DocumentTreeAccessChecker $access */
        $access = self::getContainer()->get(DocumentTreeAccessChecker::class);
        self::assertTrue($access->holdsProfile($teacher, $jefe, $fp));
        self::assertTrue($access->holdsProfile($teacher, $jefe, $dept));
    }

    public function testSlotsOfAnActivityOverTheSameTreeThatOwnsThemAreFinite(): void
    {
        ['centre' => $centre, 'dept' => $dept, 'infor' => $infor, 'sani' => $sani, 'mates' => $mates, 'jefe' => $jefe] = $this->selfReferentialTree();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $folder->addUploadProfile($jefe, null);
        // The activity's list is the very tree the profile (and the associations) point into.
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder)->setListItem($dept);
        $this->persist($category, $section, $folder, $activity);

        /** @var ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);
        $slots   = array_map(
            static fn (ActivitySubmissionSlot $s): string => $s->displayName . '|' . ($s->listItem?->getName() ?? '-'),
            $builder->buildSlots($activity),
        );
        sort($slots);

        // The root's own association (subprofile "Departamento", covering every leaf) applies to the
        // leaves that no closer ancestor has associations for: ESO/Bach's. FP's leaves take FP's.
        self::assertSame(
            [
                'ESO/Bach › Matemáticas|Informática', 'ESO/Bach › Matemáticas|Matemáticas', 'ESO/Bach › Matemáticas|Sanidad',
                'FP › Informática|Informática', 'FP › Informática|Sanidad',
                'FP › Sanidad|Informática', 'FP › Sanidad|Sanidad',
            ],
            $slots,
        );
        unset($infor, $sani, $mates);
    }

    public function testDeletingABranchWithAssociationsPointingIntoItIsClean(): void
    {
        ['centre' => $centre, 'dept' => $dept, 'fp' => $fp, 'infor' => $infor, 'sani' => $sani, 'mates' => $mates, 'jefe' => $jefe] = $this->selfReferentialTree();
        // An element OUTSIDE the branch, associated with a subprofile inside it.
        $outside = (new ListItem())->setEducationalCentre($centre)->setName('Fuera');
        $outside->addProfileAssociation($jefe, $infor);
        $this->persist($outside);
        $ids = [
            'fp' => $fp->getId()->toRfc4122(), 'infor' => $infor->getId()->toRfc4122(), 'sani' => $sani->getId()->toRfc4122(),
            'outside' => $outside->getId()->toRfc4122(),
        ];

        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        // Exactly what ListItemTreeComponent::deleteSelected() does: deepest descendants first.
        foreach (array_reverse($items->findSubtree($fp)) as $node) {
            $this->em->remove($node);
        }
        $this->em->flush();
        $this->em->clear();

        self::assertNull($items->findByIdAndCentre($ids['fp'], $centre));
        self::assertNull($items->findByIdAndCentre($ids['infor'], $centre));
        $reloadedOutside = $items->findByIdAndCentre($ids['outside'], $centre);
        self::assertNotNull($reloadedOutside, 'the element outside the branch stays');
        self::assertCount(0, $reloadedOutside->getProfileAssociations(), 'its association to a deleted subprofile goes with it');
        $left = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM list_item_profile');
        self::assertSame(1, (int) $left, 'only the root\'s own association remains');
        unset($dept, $mates);
    }

    /** Corrupted data (a parent loop, impossible through the app) must not hang the walks. */
    public function testAParentLoopInTheDataDoesNotHangAnyWalk(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $a      = (new ListItem())->setEducationalCentre($centre)->setName('A');
        $b      = (new ListItem())->setEducationalCentre($centre)->setName('B');
        $this->persist($centre, $a, $b);
        // Not reachable through setParent(): force the loop A -> B -> A at the database level.
        $this->em->getConnection()->executeStatement('UPDATE list_item SET parent_id = ? WHERE id = ?', [$b->getId()->toBinary(), $a->getId()->toBinary()], [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY]);
        $this->em->getConnection()->executeStatement('UPDATE list_item SET parent_id = ? WHERE id = ?', [$a->getId()->toBinary(), $b->getId()->toBinary()], [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY]);
        $this->em->clear();

        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $loaded = $items->findByIdAndCentre($a->getId()->toRfc4122(), $centre);
        self::assertNotNull($loaded);

        self::assertNull($loaded->getAssociationSource());
        self::assertContains($a->getId()->toRfc4122(), $items->findSelfAndAncestorIds($loaded));
        self::assertNotNull($items->findLeafDescendants($loaded));
        self::assertNotNull($items->findSubtree($loaded));
    }
}
