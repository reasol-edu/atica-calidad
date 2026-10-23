<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Tests\Integration\RepositoryTestCase;

final class ActivitySubmissionSlotBuilderTest extends RepositoryTestCase
{
    private ActivitySubmissionSlotBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ActivitySubmissionSlotBuilder $builder */
        $builder      = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);
        $this->builder = $builder;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function activity(ActivityCategory $category): Activity
    {
        return (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testNoFolderMeansNoSlots(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $this->persist($centre, $category, $activity);

        self::assertSame([], $this->builder->buildSlots($activity));
    }

    public function testWithoutAListItemOneSlotPerFolderUploadRow(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(1, $slots);
        self::assertSame($profile, $slots[0]->profile);
        self::assertNull($slots[0]->listItem);
        self::assertNull($slots[0]->nameListItem);
        self::assertNull($slots[0]->teacher);
        self::assertSame('Secretario/a', $slots[0]->displayName);
    }

    public function testUnassociatedLeafFallsBackToTheFoldersSoleUploadRow(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);

        $root = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $leaf = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $leaf->setParent($root);
        // No association at all: with a single folder upload row, the leaf is still a submission,
        // attributed to that row.

        $activity = $this->activity($category)->setFolder($folder)->setListItem($root);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $root, $leaf, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(1, $slots);
        self::assertSame($profile, $slots[0]->profile);
        self::assertNull($slots[0]->listItem);
        self::assertSame($leaf, $slots[0]->nameListItem);
        self::assertSame('Matemáticas', $slots[0]->displayName);
    }

    public function testUnassociatedLeafProducesNoSlotWhenTheFolderHasSeveralUploadRows(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $jefatura  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $secretaria = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretaría');
        $folder->addUploadProfile($jefatura);
        $folder->addUploadProfile($secretaria);

        $root = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $leaf = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $leaf->setParent($root);

        $activity = $this->activity($category)->setFolder($folder)->setListItem($root);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $jefatura, $secretaria, $root, $leaf, $activity);

        self::assertSame([], $this->builder->buildSlots($activity), 'an unassociated leaf cannot be pinned to one of several upload rows');
    }

    public function testSubmissionNameIsThePathBelowTheSelectedElement(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);

        $materias = (new ListItem())->setEducationalCentre($centre)->setName('Materias');
        $ciencias = (new ListItem())->setEducationalCentre($centre)->setName('Ciencias');
        $rama     = (new ListItem())->setEducationalCentre($centre)->setName('Experimentales');
        $fisica   = (new ListItem())->setEducationalCentre($centre)->setName('Física');
        $ciencias->setParent($materias);
        $rama->setParent($ciencias);
        $fisica->setParent($rama);

        // The activity is pinned to the intermediate node, not the root.
        $activity = $this->activity($category)->setFolder($folder)->setListItem($ciencias);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $materias, $ciencias, $rama, $fisica, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(1, $slots);
        self::assertSame('Experimentales › Física', $slots[0]->displayName, 'path runs from just below the selected element down to the leaf');
    }

    public function testSlotsNameTheirOwnerOnlyWhenAnElementIsOwedByMoreThanOne(): void
    {
        ['activity' => $activity, 'jefe' => $jefe, 'infor' => $infor, 'sani' => $sani, 'evalFp' => $evalFp, 'deptFp' => $deptFp] = $this->evaluationScenario();
        $evalFp->addProfileAssociation($jefe, $deptFp);
        $this->em->flush();

        $labels = array_map(static fn (ActivitySubmissionSlot $s): ?string => $s->ownerLabel, $this->builder->buildSlots($activity));
        self::assertNotContains(null, $labels, 'every FP leaf is owed by two heads: each row says which');
        self::assertCount(2, array_unique($labels));

        // One owner per element: nothing to tell apart, no label.
        $evalFp->clearProfileAssociations()->addProfileAssociation($jefe, $infor);
        $this->em->flush();
        $labels = array_map(static fn (ActivitySubmissionSlot $s): ?string => $s->ownerLabel, $this->builder->buildSlots($activity));
        self::assertSame([null], array_values(array_unique($labels)));
        unset($sani);
    }

    public function testListItemExpandsToOneSlotPerMatchingLeaf(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);

        $root        = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $matematicas = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $lengua      = (new ListItem())->setEducationalCentre($centre)->setName('Lengua');
        $matematicas->setParent($root);
        $lengua->setParent($root);
        $matematicas->setAssociation($profile, null);
        $lengua->setAssociation($profile, null);

        $activity = $this->activity($category)->setFolder($folder)->setListItem($root);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $root, $matematicas, $lengua, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(2, $slots);
        $names = array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName, $slots);
        sort($names);
        self::assertSame(['Lengua', 'Matemáticas'], $names);
        foreach ($slots as $slot) {
            self::assertSame($profile, $slot->profile);
            self::assertNull($slot->listItem);
        }
    }

    /**
     * Matching is by identity of (row->profile, row->listItem) against the leaf's own
     * (getAssociatedProfile(), getAssociatedProfileListItem()) — a leaf associated with a
     * DIFFERENT subprofile of a list-associated profile must not produce a slot for a row that
     * expects a different subprofile.
     */
    public function testLeafAssociatedWithADifferentSubprofileDoesNotMatch(): void
    {
        $centre = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);

        $departamentos = (new ListItem())->setEducationalCentre($centre)->setName('Departamentos');
        $matematicasDpto = (new ListItem())->setEducationalCentre($centre)->setName('Dpto. Matemáticas');
        $lenguaDpto      = (new ListItem())->setEducationalCentre($centre)->setName('Dpto. Lengua');
        $matematicasDpto->setParent($departamentos);
        $lenguaDpto->setParent($departamentos);

        $jefatura = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($departamentos);
        $folder->addUploadProfile($jefatura, $matematicasDpto); // folder only accepts the Matemáticas subprofile

        $materias = (new ListItem())->setEducationalCentre($centre)->setName('Materias');
        $matematicasMateria = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $lenguaMateria       = (new ListItem())->setEducationalCentre($centre)->setName('Lengua');
        $matematicasMateria->setParent($materias);
        $lenguaMateria->setParent($materias);
        $matematicasMateria->setAssociation($jefatura, $matematicasDpto);
        $lenguaMateria->setAssociation($jefatura, $lenguaDpto); // associated with the OTHER subprofile

        $activity = $this->activity($category)->setFolder($folder)->setListItem($materias);

        $this->persist(
            $centre, $category, $folder->getDocumentSection(), $folder,
            $departamentos, $matematicasDpto, $lenguaDpto, $jefatura,
            $materias, $matematicasMateria, $lenguaMateria, $activity,
        );

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(1, $slots);
        self::assertSame('Matemáticas', $slots[0]->displayName);
        self::assertSame($matematicasDpto, $slots[0]->listItem);
    }

    /**
     * "Departamento" (ESO/Bach > Matemáticas; FP > Informática, Sanidad) is the list of the profile
     * Jefatura, with the folder accepting any of its subprofiles; "Evaluación" is the activity's list
     * (ESO/Bach > 1ªEV, 2ªEV, Ordinaria, Extraordinaria; FP > 1ªEV, 2ªEV, Final 1, Final 2).
     *
     * @return array<string, mixed>
     */
    private function evaluationScenario(): array
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);

        $dept   = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $deptEso = (new ListItem())->setEducationalCentre($centre)->setName('ESO/Bach')->setParent($dept);
        $mates  = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas')->setParent($deptEso);
        $deptFp = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($dept);
        $infor  = (new ListItem())->setEducationalCentre($centre)->setName('Informática')->setParent($deptFp);
        $sani   = (new ListItem())->setEducationalCentre($centre)->setName('Sanidad')->setParent($deptFp);
        $jefe   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($dept);
        $folder->addUploadProfile($jefe, null);

        $evaluation = (new ListItem())->setEducationalCentre($centre)->setName('Evaluación');
        $evalEso    = (new ListItem())->setEducationalCentre($centre)->setName('ESO/Bach')->setParent($evaluation);
        $evalFp     = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($evaluation);
        $esoLeaves  = [];
        foreach (['1ªEV', '2ªEV', 'Ordinaria', 'Extraordinaria'] as $name) {
            $esoLeaves[] = (new ListItem())->setEducationalCentre($centre)->setName($name)->setParent($evalEso);
        }
        $fpLeaves = [];
        foreach (['1ªEV', '2ªEV', 'Final 1', 'Final 2'] as $name) {
            $fpLeaves[] = (new ListItem())->setEducationalCentre($centre)->setName($name)->setParent($evalFp);
        }

        $activity = $this->activity($category)->setFolder($folder)->setListItem($evaluation);
        $this->persist(
            $centre, $category, $folder->getDocumentSection(), $folder,
            $dept, $deptEso, $mates, $deptFp, $infor, $sani, $jefe,
            $evaluation, $evalEso, $evalFp, $activity, ...$esoLeaves, ...$fpLeaves,
        );

        return compact('centre', 'folder', 'activity', 'jefe', 'deptFp', 'infor', 'sani', 'mates', 'evalFp', 'evalEso', 'fpLeaves', 'esoLeaves');
    }

    /** @param ActivitySubmissionSlot[] $slots @return list<string> "leaf|owner" sorted */
    private function slotSummary(array $slots): array
    {
        $summary = array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName . '|' . ($s->listItem?->getName() ?? '-'), $slots);
        sort($summary);

        return $summary;
    }

    public function testAnAssociationToAnIntermediateNodeOwesOneSubmissionPerSubprofileUnderItForEveryLeafOfTheBranch(): void
    {
        ['activity' => $activity, 'jefe' => $jefe, 'deptFp' => $deptFp, 'evalFp' => $evalFp] = $this->evaluationScenario();
        $evalFp->addProfileAssociation($jefe, $deptFp); // the branch FP of "Evaluación" belongs to the heads of FP
        $this->em->flush();

        $slots = $this->builder->buildSlots($activity);

        self::assertSame(
            [
                'FP › 1ªEV|Informática', 'FP › 1ªEV|Sanidad',
                'FP › 2ªEV|Informática', 'FP › 2ªEV|Sanidad',
                'FP › Final 1|Informática', 'FP › Final 1|Sanidad',
                'FP › Final 2|Informática', 'FP › Final 2|Sanidad',
            ],
            $this->slotSummary($slots),
            '4 leaves of FP x 2 heads of FP (Informática, Sanidad); ESO/Bach, with no owner and several folder rows, asks nobody',
        );
        foreach ($slots as $slot) {
            self::assertSame($jefe, $slot->profile);
            self::assertStringStartsWith('FP › ', $slot->displayName);
        }
    }

    public function testALeafWithNoAssociationOfItsOwnTakesTheOnesOfItsClosestAssociatedParent(): void
    {
        ['activity' => $activity, 'jefe' => $jefe, 'infor' => $infor, 'evalFp' => $evalFp, 'evalEso' => $evalEso, 'mates' => $mates] = $this->evaluationScenario();
        $evalFp->addProfileAssociation($jefe, $infor);
        $evalEso->addProfileAssociation($jefe, $mates);
        $this->em->flush();

        $summary = $this->slotSummary($this->builder->buildSlots($activity));

        self::assertCount(8, $summary);
        self::assertCount(4, array_filter($summary, static fn (string $s): bool => str_ends_with($s, '|Informática')));
        self::assertCount(4, array_filter($summary, static fn (string $s): bool => str_ends_with($s, '|Matemáticas')));
    }

    public function testALeafWithItsOwnAssociationsIgnoresTheParents(): void
    {
        ['activity' => $activity, 'jefe' => $jefe, 'infor' => $infor, 'sani' => $sani, 'evalFp' => $evalFp, 'fpLeaves' => $fpLeaves] = $this->evaluationScenario();
        $evalFp->addProfileAssociation($jefe, $infor);
        $fpLeaves[0]->addProfileAssociation($jefe, $sani); // the first leaf is only for Sanidad
        $this->em->flush();

        $summary = $this->slotSummary($this->builder->buildSlots($activity));

        self::assertSame(
            ['FP › 1ªEV|Sanidad', 'FP › 2ªEV|Informática', 'FP › Final 1|Informática', 'FP › Final 2|Informática'],
            $summary,
        );
    }

    public function testALeafWithSeveralAssociationsOwesOneSubmissionPerOwner(): void
    {
        ['activity' => $activity, 'jefe' => $jefe, 'infor' => $infor, 'sani' => $sani, 'fpLeaves' => $fpLeaves] = $this->evaluationScenario();
        $fpLeaves[1]->addProfileAssociation($jefe, $infor)->addProfileAssociation($jefe, $sani);
        $this->em->flush();

        self::assertSame(['FP › 2ªEV|Informática', 'FP › 2ªEV|Sanidad'], $this->slotSummary($this->builder->buildSlots($activity)));
    }

    public function testInIndividualScopeEachHeadOfFpOwesOneSubmissionPerLeafOfTheBranch(): void
    {
        ['activity' => $activity, 'jefe' => $jefe, 'deptFp' => $deptFp, 'infor' => $infor, 'sani' => $sani, 'evalFp' => $evalFp] = $this->evaluationScenario();
        $evalFp->addProfileAssociation($jefe, $deptFp);
        $activity->setSubmissionScope(ActivitySubmissionScope::Individual);
        $tInfor = $this->teacher('infor');
        $tSani  = $this->teacher('sani');
        $this->persist($tInfor, $tSani, new SpecificProfileAssignment($jefe, $infor, $tInfor), new SpecificProfileAssignment($jefe, $sani, $tSani));

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(8, $slots, '2 heads x 4 leaves of FP');
        foreach (['infor', 'sani'] as $username) {
            $own = array_filter($slots, static fn (ActivitySubmissionSlot $s): bool => $s->teacher?->getUsername() === $username);
            self::assertCount(4, $own, $username);
        }
    }

    public function testAnAssociationOnlyOwesWhatTheFolderAccepts(): void
    {
        ['activity' => $activity, 'folder' => $folder, 'jefe' => $jefe, 'infor' => $infor, 'deptFp' => $deptFp, 'evalFp' => $evalFp] = $this->evaluationScenario();
        $evalFp->addProfileAssociation($jefe, $deptFp);
        $folder->getUploadProfiles()->clear();
        $folder->addUploadProfile($jefe, $infor); // the folder takes uploads from Informática only
        $this->em->flush();

        $summary = $this->slotSummary($this->builder->buildSlots($activity));

        $fp = array_values(array_filter($summary, static fn (string $s): bool => str_starts_with($s, 'FP › ')));
        self::assertSame(['FP › 1ªEV|Informática', 'FP › 2ªEV|Informática', 'FP › Final 1|Informática', 'FP › Final 2|Informática'], $fp, 'Sanidad is not among the folder\'s rows: it owes nothing');
        // The leaves with no owner at all fall back to the folder's single upload row, as before.
        self::assertCount(4, array_filter($summary, static fn (string $s): bool => str_starts_with($s, 'ESO/Bach › ')));
    }

    /** With several folder rows, which leaves are asked (and of whom) is decided by the associations alone, own or inherited. */
    public function testOnlyTheLeavesWithAnOwnerAreAskedWhenTheFolderHasSeveralRows(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $jefatura = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $tutoria  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutoría');
        $folder->addUploadProfile($jefatura);
        $folder->addUploadProfile($tutoria);

        $root    = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $group   = (new ListItem())->setEducationalCentre($centre)->setName('Ciencias')->setParent($root);
        $owned   = (new ListItem())->setEducationalCentre($centre)->setName('Física')->setParent($group);
        $own     = (new ListItem())->setEducationalCentre($centre)->setName('Química')->setParent($group);
        $nobody  = (new ListItem())->setEducationalCentre($centre)->setName('Dibujo')->setParent($root);
        $group->addProfileAssociation($jefatura);
        $own->addProfileAssociation($tutoria);

        $activity = $this->activity($category)->setFolder($folder)->setListItem($root);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $jefatura, $tutoria, $root, $group, $owned, $own, $nobody, $activity);

        $slots = array_map(
            static fn (ActivitySubmissionSlot $s): string => $s->displayName . '|' . $s->profile->getName(),
            $this->builder->buildSlots($activity),
        );
        sort($slots);

        self::assertSame(['Ciencias › Física|Jefatura', 'Ciencias › Química|Tutoría'], $slots, 'Dibujo has no owner and the folder has two rows: nobody is asked');
    }

    public function testByProfileScopeKeepsSlotsSharedWithNoTeacher(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $teacherA = $this->teacher('a');
        $teacherB = $this->teacher('b');
        $assignA  = new SpecificProfileAssignment($profile, null, $teacherA);
        $assignB  = new SpecificProfileAssignment($profile, null, $teacherB);

        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $teacherA, $teacherB, $assignA, $assignB, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(1, $slots, 'By-profile scope produces one shared slot regardless of how many teachers hold the profile');
        self::assertNull($slots[0]->teacher);
    }

    public function testIndividualScopeExpandsIntoOneSlotPerHoldingTeacher(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $folder->addUploadProfile($profile);
        $teacherA = $this->teacher('sergio');
        $teacherB = $this->teacher('javier');
        $assignA  = new SpecificProfileAssignment($profile, null, $teacherA);
        $assignB  = new SpecificProfileAssignment($profile, null, $teacherB);

        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $teacherA, $teacherB, $assignA, $assignB, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(2, $slots);
        $teacherUsernames = array_map(static fn (ActivitySubmissionSlot $s): ?string => $s->teacher?->getUsername(), $slots);
        sort($teacherUsernames);
        self::assertSame(['javier', 'sergio'], $teacherUsernames);
    }

    /**
     * Regression: Individual scope must expand a wildcard folder-upload row into every teacher
     * currently holding ANY subprofile of that list-associated profile — using
     * findTeachersHoldingProfileAndListItem(), not the exact-match lookup, or a teacher assigned
     * to just one subprofile of a wildcard-accepting folder would be silently excluded.
     */
    public function testIndividualScopeExpandsWildcardRowToTeachersOfAnySubprofile(): void
    {
        $centre = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);

        $root = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $leafA = (new ListItem())->setEducationalCentre($centre)->setName('1º DAW A');
        $leafB = (new ListItem())->setEducationalCentre($centre)->setName('1º DAW B');
        $leafA->setParent($root);
        $leafB->setParent($root);

        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setListItem($root);
        $folder->addUploadProfile($profile, null); // wildcard: accepts any subprofile of Tutor/a

        $teacherA = $this->teacher('sergio');
        $teacherB = $this->teacher('javier');
        $assignA  = new SpecificProfileAssignment($profile, $leafA, $teacherA);
        $assignB  = new SpecificProfileAssignment($profile, $leafB, $teacherB);

        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $root, $leafA, $leafB, $profile, $teacherA, $teacherB, $assignA, $assignB, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(2, $slots);
        $teacherUsernames = array_map(static fn (ActivitySubmissionSlot $s): ?string => $s->teacher?->getUsername(), $slots);
        sort($teacherUsernames);
        self::assertSame(['javier', 'sergio'], $teacherUsernames);
    }

    // ── resolveSlot() ─────────────────────────────────────────────────────────

    public function testResolveSlotReturnsNullWhenActivityHasNoFolder(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $this->persist($centre, $category, $profile, $activity);

        $slot = new ActivitySubmissionSlot($profile, null, null, 'Entrega', null);

        self::assertNull($this->builder->resolveSlot($activity, $slot));
    }

    public function testResolveSlotReturnsNullWhenNothingUploadedYet(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $activity = $this->activity($category)->setFolder($folder);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity);

        $slot = new ActivitySubmissionSlot($profile, null, null, 'Entrega', null);

        self::assertNull($this->builder->resolveSlot($activity, $slot));
    }

    public function testResolveSlotFindsAnExistingUpload(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);
        $teacher  = $this->teacher('docente');

        $document = new Document($folder, 'Entrega');
        $document->setUploadProfile($profile, null);
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $teacher);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $document, $file, $revision);

        $slot  = new ActivitySubmissionSlot($profile, null, null, 'Entrega', null);
        $found = $this->builder->resolveSlot($activity, $slot);

        self::assertNotNull($found);
        self::assertSame($document->getId()->toRfc4122(), $found->getId()->toRfc4122());
    }
}
