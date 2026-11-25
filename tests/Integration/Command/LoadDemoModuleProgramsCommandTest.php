<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Activity;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Repository\ActivityRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\FormModelRevisionRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\TeacherRepository;
use App\Service\Form\FormApprovalChain;
use App\Service\Form\FormDefinition;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityObligationFinder;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\DocumentTreeAccessChecker;
use App\Service\SlotResponsibility;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The case of the module programmes (phase 0b, «Docentes asociados») as `app:load-demo-module-programs` builds it: the acceptance of the
 * phase on the demo data (see skills/screenshots.md).
 */
final class LoadDemoModuleProgramsCommandTest extends ControllerTestCase
{
    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel ?? self::bootKernel());
        foreach (['app:load-demo-data', 'app:load-demo-module-programs'] as $name) {
            $tester = new CommandTester($application->find($name));
            $tester->execute([]);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            // Two separate processes in real life: nothing of the first one stays in memory (inverse collections included).
            $this->em->clear();
            self::getContainer()->get('services_resetter')->reset();
        }

        $activity = null;
        foreach (self::getContainer()->get(ActivityRepository::class)->findAll() as $candidate) {
            if ($candidate->getTitle() === 'Entrega de programaciones de los módulos') {
                $activity = $candidate;
            }
        }
        self::assertNotNull($activity);
        $this->activity = $activity;
    }

    private function teacher(string $username): Teacher
    {
        $teacher = self::getContainer()->get(TeacherRepository::class)->findByUsername($username);
        self::assertNotNull($teacher, $username);

        return $teacher;
    }

    /** @param ActivitySubmissionSlot[] $slots @return list<string> */
    private static function names(array $slots): array
    {
        $names = array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName, $slots);
        sort($names);

        return $names;
    }

    public function testTheActivityAsTheSpecificationDescribesIt(): void
    {
        self::assertTrue($this->activity->isTeachersDeliver());
        self::assertSame('Materia', $this->activity->getListItem()?->getName());
        self::assertSame('Programación didáctica del módulo', $this->activity->getFormModel()?->getDocument()->getName());
        self::assertSame('Programaciones de los módulos', $this->activity->getFolder()?->getName());
        $model = $this->activity->getFormModel();
        self::assertNotNull($model);
        $chain = FormDefinition::listOf(self::getContainer()->get(FormModelRevisionRepository::class)->findInForce($model)?->getDefinition()['approval'] ?? []);
        self::assertSame(['element', 'any'], array_column($chain, 'scope'), 'the heads of each department, then Jefatura de Estudios');
        self::assertCount(15, self::getContainer()->get(ActivitySubmissionSlotBuilder::class)->buildSlots($this->activity), '14 modules, and Tecnología is owed by two departments');
    }

    public function testNoeliaSeesInMyActivitiesProgramacionAndBasesDeDatosAndNothingElse(): void
    {
        $noelia = $this->teacher('n.bravo');
        $completion = self::getContainer()->get(ActivityCompletionChecker::class);

        self::assertSame(['Bases de Datos', 'Programación'], self::names($completion->getMyOwnedSlots($noelia, $this->activity)));
        self::assertCount(2, self::getContainer()->get(ActivityObligationFinder::class)->forActivity($noelia, $this->activity));
    }

    public function testClaudiaOwnsWhatHasNoTeacherInTheCourseAndManagesTheRestOfInformatica(): void
    {
        $claudia    = $this->teacher('c.núñez');
        $completion = self::getContainer()->get(ActivityCompletionChecker::class);

        $owned = self::names($completion->getMyOwnedSlots($claudia, $this->activity));
        self::assertContains('Sistemas Informáticos', $owned, 'nobody teaches it');
        self::assertContains('Fase de Formación en Empresa u Organismo Equiparado (FFEOE)', $owned, 'its teacher is not in the course');
        foreach (['Programación', 'Bases de Datos', 'Desarrollo Web en Entorno Cliente', 'Desarrollo Web en Entorno Servidor'] as $module) {
            self::assertNotContains($module, $owned, $module . ' is its teachers\'');
            self::assertContains($module, self::names($completion->getMySlots($claudia, $this->activity)), 'but she may fill it in');
        }
        self::assertNotContains('Matemáticas', self::names($completion->getMySlots($claudia, $this->activity)), 'another department\'s');
    }

    public function testTheStartingStateAndWhoCanOpenWhat(): void
    {
        $submissions = self::getContainer()->get(FormSubmissionRepository::class)->findBy(['activity' => $this->activity]);
        $states      = [];
        foreach ($submissions as $submission) {
            $states[$submission->getSlotKey()] = $submission->hasDraft() ? 'draft' : 'delivered';
        }
        self::assertCount(3, $submissions, 'only the three the starting state needs');
        self::assertEqualsCanonicalizing(['delivered', 'delivered', 'draft'], array_values($states));

        $slots = [];
        foreach (self::getContainer()->get(ActivitySubmissionSlotBuilder::class)->buildSlots($this->activity) as $slot) {
            $slots[$slot->displayName] ??= $slot;
        }
        $responsibility = self::getContainer()->get(SlotResponsibility::class);
        $rocio          = $this->teacher('r.serrano');
        self::assertTrue($responsibility->owns($rocio, $this->activity, $slots['Desarrollo Web en Entorno Servidor']));
        self::assertSame('draft', $states[$slots['Desarrollo Web en Entorno Servidor']->key()] ?? null, 'Rocío\'s draft');
        self::assertSame('delivered', $states[$slots['Programación']->key()] ?? null, 'Noelia delivered Programación');

        // r.fuentes is no longer in the course: FFEOE is not his.
        self::assertFalse($responsibility->canFill($this->teacher('r.fuentes'), $this->activity, $slots['Fase de Formación en Empresa u Organismo Equiparado (FFEOE)']));

        // The delivered document is open to Noelia, who teaches it, and to the head; nobody else of the staff holds the profile.
        $document = $this->activity->getFolder()?->getDocuments()->first();
        self::assertNotFalse($document);
        $access = self::getContainer()->get(DocumentTreeAccessChecker::class);
        self::assertTrue($access->canViewDocument($this->teacher('n.bravo'), $document));
        self::assertTrue($access->canManageDocumentAsUploader($this->teacher('n.bravo'), $document));
    }

    /** Phase 0c on the demo: each delivery waits for the head of its own department, and Claudia's own step was approved by delivering. */
    public function testTheQueuesOfEachHeadAndTheStepApprovedByDelivering(): void
    {
        $centre = self::getContainer()->get(EducationalCentreRepository::class)->findByCode('29700456');
        self::assertNotNull($centre);
        $chain   = self::getContainer()->get(FormApprovalChain::class);
        $waiting = fn (string $username): array => array_map(
            static fn ($step): string => $step->getRevision()->getRevision()->getDocument()->getName() . ' · ' . $step->getName(),
            $chain->pendingFor($this->teacher($username), $centre),
        );

        self::assertContains('Programación · Jefatura del departamento', $waiting('c.núñez'));
        self::assertNotContains('Programación · Jefatura del departamento', $waiting('p.sánchez'), 'the head of Matemáticas does not decide it');
        self::assertNotContains('Programación · Jefatura del departamento', $waiting('g.ortiz'), 'nor Jefatura de Estudios, before its turn');
        self::assertContains('Sistemas Informáticos · Jefatura de estudios', $waiting('g.ortiz'), 'its turn has come');

        $sistemas = null;
        foreach ($this->activity->getFolder()?->getDocuments() ?? [] as $document) {
            $sistemas = $document->getName() === 'Sistemas Informáticos' ? $document : $sistemas;
        }
        self::assertNotNull($sistemas);
        $steps = $chain->stepsOf($sistemas->getRevisions()->last() ?: throw new \LogicException('no revision'));
        self::assertSame(['approved', 'pending'], array_map(static fn ($s): string => $s->getState()->value, $steps), 'Claudia delivered it: her step is approved');
        self::assertSame('Entregado y aprobado por la misma persona.', $steps[0]->getNote());
    }

    public function testNoeliaOpensTheFormOfHerModuleAndNotTheOneOfSistemas(): void
    {
        $slots = [];
        foreach (self::getContainer()->get(ActivitySubmissionSlotBuilder::class)->buildSlots($this->activity) as $slot) {
            $slots[$slot->displayName] ??= $slot;
        }
        $centre = self::getContainer()->get(EducationalCentreRepository::class)->findByCode('29700456');
        self::assertNotNull($centre);
        $url = static fn (string $name, Activity $a, array $slots): string => '/actividades/' . $a->getId()->toRfc4122() . '/formulario/' . $slots[$name]->key();

        $this->loginAs($this->teacher('n.bravo'), $centre);
        $this->client->request('GET', $url('Bases de Datos', $this->activity, $slots));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $url('Sistemas Informáticos', $this->activity, $slots));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
