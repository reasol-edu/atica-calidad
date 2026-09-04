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
use App\Entity\Tag;
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

    public function testTagFilterExcludesLeavesMissingARequiredTag(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);

        $tag = (new Tag())->setEducationalCentre($centre)->setName('Bachillerato');

        $root       = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $tagged     = (new ListItem())->setEducationalCentre($centre)->setName('Física');
        $untagged   = (new ListItem())->setEducationalCentre($centre)->setName('Dibujo');
        $tagged->setParent($root);
        $untagged->setParent($root);
        $tagged->setAssociation($profile, null);
        $untagged->setAssociation($profile, null);
        $tagged->addTag($tag);

        $activity = $this->activity($category)->setFolder($folder)->setListItem($root);
        $activity->addTag($tag);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $tag, $root, $tagged, $untagged, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(1, $slots);
        self::assertSame('Física', $slots[0]->displayName);
    }

    public function testTagFilterHonoursInheritedAncestorTags(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);

        $tag = (new Tag())->setEducationalCentre($centre)->setName('Bachillerato');

        $root  = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $group = (new ListItem())->setEducationalCentre($centre)->setName('Ciencias');
        $leaf  = (new ListItem())->setEducationalCentre($centre)->setName('Física');
        $group->setParent($root);
        $leaf->setParent($group);
        $leaf->setAssociation($profile, null);
        $group->addTag($tag); // tag lives on the ancestor, not the leaf itself

        $activity = $this->activity($category)->setFolder($folder)->setListItem($root);
        $activity->addTag($tag);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $tag, $root, $group, $leaf, $activity);

        $slots = $this->builder->buildSlots($activity);

        self::assertCount(1, $slots);
        self::assertSame('Ciencias › Física', $slots[0]->displayName);
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
