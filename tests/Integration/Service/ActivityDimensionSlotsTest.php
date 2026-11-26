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
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\DocumentCreationService;
use App\Tests\Integration\RepositoryTestCase;

/**
 * Activities with dimensions (ActivityDimension): one submission per combination of an element with one value of each dimension — the
 * values an element is linked to for an associated list, all of them for a free one — or, with the elements gathered, one per owner
 * and combination.
 */
final class ActivityDimensionSlotsTest extends RepositoryTestCase
{
    /** @var array<string, ListItem> */
    private array $items = [];

    private EducationalCentre $centre;

    private SpecificProfile $head;

    private ActivityCategory $category;

    private function item(string $name, ?ListItem $parent = null): ListItem
    {
        $item = (new ListItem())->setEducationalCentre($this->centre)->setName($name)->setPosition(count($this->items));
        if ($parent !== null) {
            $item->setParent($parent);
        }

        return $this->items[($parent !== null ? $parent->getName() . '/' : '') . $name] = $item;
    }

    /**
     * Departamento: Informática, Matemáticas. Materia: Programación (Informática; 1ª, 2ª, Final 1), Matemáticas (Matemáticas; 1ª, 2ª,
     * Ord.), Tutoría (Matemáticas; no evaluation). Evaluación: 1ª, 2ª, Ord., Final 1. Trimestre: 1T, 2T. «Jefatura» on Departamento.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->category = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');

        $depts = $this->item('Departamento');
        $infor = $this->item('Informática', $depts);
        $maths = $this->item('Matemáticas', $depts);
        $evals = $this->item('Evaluación');
        foreach (['1ª', '2ª', 'Ord.', 'Final 1'] as $name) {
            $this->item($name, $evals);
        }
        $terms = $this->item('Trimestre');
        $this->item('1T', $terms);
        $this->item('2T', $terms);

        $this->head = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura')->setListItem($depts);
        $subjects   = $this->item('Materia')->addLinkedList($evals);
        $prog       = $this->item('Programación', $subjects)->addProfileAssociation($this->head, $infor);
        $mat        = $this->item('Matemáticas', $subjects)->addProfileAssociation($this->head, $maths);
        $this->item('Tutoría', $subjects)->addProfileAssociation($this->head, $maths);
        foreach (['1ª', '2ª', 'Final 1'] as $name) {
            $prog->addLink($this->items['Evaluación/' . $name]);
        }
        foreach (['1ª', '2ª', 'Ord.'] as $name) {
            $mat->addLink($this->items['Evaluación/' . $name]);
        }

        $this->persist($this->centre, $this->category, ...array_values($this->items));
        $this->persist($this->head);
    }

    /** @param list<ListItem> $dimensions */
    private function activity(?ListItem $listItem, array $dimensions, bool $split = true, ?int $dated = null): Activity
    {
        $section = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $folder->addUploadProfile($this->head);
        $activity = (new Activity())->setCategory($this->category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6)
            ->setFolder($folder)->setListItem($listItem)->setDimensions($dimensions, $dated)->setSplitByElement($split);
        $this->persist($section, $folder, $activity);

        return $activity;
    }

    /** @return list<string> */
    private function names(Activity $activity): array
    {
        /** @var ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);

        return array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName . ($s->ownerLabel !== null ? ' — ' . $s->ownerLabel : ''), $builder->buildSlots($activity));
    }

    public function testAnAssociatedDimensionGivesEachElementItsOwnValuesAndAnElementWithoutAnyAsksNothing(): void
    {
        $activity = $this->activity($this->items['Materia'], [$this->items['Evaluación']]);

        self::assertSame(
            ['Programación · 1ª', 'Programación · 2ª', 'Programación · Final 1', 'Matemáticas · 1ª', 'Matemáticas · 2ª', 'Matemáticas · Ord.'],
            $this->names($activity),
            'Tutoría has no evaluation: no submission',
        );
    }

    public function testTheKeyHasAFifthPartWithTheCombinationAndTheDatesFollowTheDatedValue(): void
    {
        $activity = $this->activity($this->items['Materia'], [$this->items['Evaluación']], true, 0);
        /** @var ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);
        $slot    = $builder->buildSlots($activity)[0];
        $first   = $this->items['Evaluación/1ª'];

        $key = $slot->key();
        self::assertCount(5, explode(':', $key));
        self::assertSame([$first->getId()->toRfc4122()], ActivitySubmissionSlot::combinationOf($key));
        self::assertSame($this->items['Materia/Programación']->getId()->toRfc4122(), ActivitySubmissionSlot::leafOf($key));
        self::assertSame(['profile' => $this->head->getId()->toRfc4122(), 'subprofile' => $this->items['Departamento/Informática']->getId()->toRfc4122()], ActivitySubmissionSlot::deliveredBy($key));
        self::assertSame($first, $slot->deadlineItem($activity), 'the evaluation gives the dates');
        self::assertSame([], ActivitySubmissionSlot::combinationOf(substr($key, 0, (int) strrpos($key, ':'))), 'a key of four parts has none');
    }

    public function testGatheringTheElementsAsksEachOwnerOncePerValueTheyHaveSubjectsIn(): void
    {
        $activity = $this->activity($this->items['Materia'], [$this->items['Evaluación']], false);

        self::assertSame(
            ['1ª — Jefatura Informática', '2ª — Jefatura Informática', 'Final 1 — Jefatura Informática', '1ª — Jefatura Matemáticas', '2ª — Jefatura Matemáticas', 'Ord. — Jefatura Matemáticas'],
            $this->names($activity),
            'Informática has no Ord., Matemáticas no Final 1; as names repeat, every row says whose',
        );
    }

    public function testAFreeDimensionRepeatsEverySubmissionForEachValue(): void
    {
        $activity = $this->activity(null, [$this->items['Trimestre']]);

        self::assertSame(
            ['Jefatura Informática · 1T', 'Jefatura Informática · 2T', 'Jefatura Matemáticas · 1T', 'Jefatura Matemáticas · 2T'],
            $this->names($activity),
        );
    }

    public function testWithoutDimensionsNothingChanges(): void
    {
        $activity = $this->activity($this->items['Materia'], []);
        /** @var ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);

        self::assertSame(['Programación', 'Matemáticas', 'Tutoría'], $this->names($activity));
        foreach ($builder->buildSlots($activity) as $slot) {
            self::assertCount(4, explode(':', $slot->key()));
            self::assertSame($slot->nameListItem, $slot->deadlineItem($activity));
        }
    }

    public function testADeliveredSubmissionIsFoundByItsCombinationEvenAfterTheValueIsRenamed(): void
    {
        $activity = $this->activity($this->items['Materia'], [$this->items['Evaluación']], true, 0);
        /** @var ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);
        /** @var DocumentCreationService $creation */
        $creation = self::getContainer()->get(DocumentCreationService::class);
        $uploader = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('ana');
        $this->persist($uploader);

        [$first, $second] = $builder->buildSlots($activity);
        $folder = $activity->getFolder();
        self::assertNotNull($folder);
        $file     = $creation->storeFile('contenido', 'text/plain', 'a.txt');
        $document = $creation->createFromStoredFile($folder, $first->displayName, $first->profile, $first->listItem, $file, $uploader, null, false, $first->combinationKey());
        $this->em->flush();

        self::assertSame($first->combinationKey(), $document->getSlotCombination());
        self::assertSame($document, $builder->resolveSlot($activity, $first));
        self::assertNull($builder->resolveSlot($activity, $second), 'the 2ª is another submission');
        self::assertSame($this->items['Materia/Programación'], $builder->findLeafForDocument($activity, $document));
        self::assertSame($this->items['Evaluación/1ª'], $builder->findDeadlineItemForDocument($activity, $document));

        $this->items['Evaluación/1ª']->setName('Primera');
        $this->em->flush();
        $renamed = $builder->buildSlots($activity)[0];
        self::assertSame('Programación · Primera', $renamed->displayName);
        self::assertSame($document, $builder->resolveSlot($activity, $renamed), 'found by the combination, not by the old name');
    }
}
