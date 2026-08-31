<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\CentreSettingValueRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\SettingDefinitionRepository;
use App\Repository\TeacherSettingValueRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class SettingsComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function definition(string $key, string $default = 'default'): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::String)->setDefaultValue($default)->setCentreScope(true)->setTeacherScope(true)->setGlobalScope(true);
    }

    /**
     * A teacher with no selected centre never actually reaches the component's own centre-null
     * guard — TenantContextSubscriber intercepts every non-admin request beforehand: it
     * auto-selects when exactly one centre is accessible, or redirects to the centre-picker
     * otherwise. This confirms that outer guard, which is what a teacher actually experiences
     * before this component (mounted with scope=centre) would ever render.
     */
    public function testCentreScopeIsUnreachableWithoutASelectedCentre(): void
    {
        $centreA = $this->centre();
        $centreB = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Otra ciudad');
        $teacher = $this->teacher('docente');
        $centreA->getAdmins()->add($teacher);
        $centreB->getAdmins()->add($teacher);
        $this->persist($centreA, $centreB, $teacher);

        $this->client->loginUser($teacher);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'centre'], $this->client);
        $component->render();

        self::assertTrue($this->client->getResponse()->isRedirect('/seleccion/centro'));
    }

    public function testTeacherScopeSavesAValueForTheCurrentTeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $def     = $this->definition('ui.greeting');
        $this->persist($centre, $teacher, $def);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'teacher'], $this->client);
        $component->call('save', ['key' => 'ui.greeting', 'value' => 'Hola']);

        $this->em->clear();
        /** @var TeacherSettingValueRepository $teacherValues */
        $teacherValues = self::getContainer()->get(TeacherSettingValueRepository::class);
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'ui.greeting']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\TeacherRepository $teachers */
        $teachers = self::getContainer()->get(\App\Repository\TeacherRepository::class);
        $reloadedTeacher = $teachers->findById($teacher->getId()->toRfc4122());
        self::assertNotNull($reloadedTeacher);
        $stored = $teacherValues->findByDefinitionAndTeacher($reloadedDef, $reloadedTeacher);
        self::assertNotNull($stored);
        self::assertSame('Hola', $stored->getValue());
    }

    public function testCentreScopeSavesAValueForTheSelectedCentre(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $def = $this->definition('reports.title');
        $this->persist($centre, $admin, $def);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'centre'], $this->client);
        $component->call('save', ['key' => 'reports.title', 'value' => 'Título del centro']);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'reports.title']);
        self::assertNotNull($reloadedDef);
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        $stored = $centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre);
        self::assertNotNull($stored);
        self::assertSame('Título del centro', $stored->getValue());
    }

    public function testSaveWithAnInvalidValueSetsLastErrorWithoutPersisting(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $def = (new SettingDefinition())->setKey('reports.count')->setType(SettingType::Integer)->setDefaultValue('0')->setCentreScope(true)->setMinValue(1)->setMaxValue(5);
        $this->persist($centre, $admin, $def);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'centre'], $this->client);
        $component->call('save', ['key' => 'reports.count', 'value' => '999']);

        $props = json_decode((string) $component->render()->crawler()->filter('[data-live-props-value]')->attr('data-live-props-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame('reports.count', $props['lastError']);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'reports.count']);
        self::assertNotNull($reloadedDef);
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        self::assertNull($centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre));
    }

    /**
     * The Stimulus controller that drives this action from the browser (setting_save_controller.js)
     * deliberately sends the boolean as the literal string "true"/"false" (String(element.value)) —
     * this proves the LiveAction's own value/type handling accepts exactly that, matching the
     * round trip the browser actually performs.
     */
    public function testTeacherScopeSavesABooleanValue(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $def     = (new SettingDefinition())->setKey('ui.compact')->setType(SettingType::Boolean)->setDefaultValue('false')->setTeacherScope(true);
        $this->persist($centre, $teacher, $def);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'teacher'], $this->client);
        $component->call('save', ['key' => 'ui.compact', 'value' => 'true']);

        $this->em->clear();
        /** @var TeacherSettingValueRepository $teacherValues */
        $teacherValues = self::getContainer()->get(TeacherSettingValueRepository::class);
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'ui.compact']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\TeacherRepository $teachers */
        $teachers = self::getContainer()->get(\App\Repository\TeacherRepository::class);
        $reloadedTeacher = $teachers->findById($teacher->getId()->toRfc4122());
        self::assertNotNull($reloadedTeacher);
        $stored = $teacherValues->findByDefinitionAndTeacher($reloadedDef, $reloadedTeacher);
        self::assertNotNull($stored);
        self::assertSame('true', $stored->getValue());
    }

    public function testSaveWithAnInvalidBooleanValueSetsLastErrorWithoutPersisting(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $def = (new SettingDefinition())->setKey('ui.compact')->setType(SettingType::Boolean)->setDefaultValue('false')->setCentreScope(true);
        $this->persist($centre, $admin, $def);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'centre'], $this->client);
        $component->call('save', ['key' => 'ui.compact', 'value' => 'yes']);

        $props = json_decode((string) $component->render()->crawler()->filter('[data-live-props-value]')->attr('data-live-props-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame('ui.compact', $props['lastError']);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'ui.compact']);
        self::assertNotNull($reloadedDef);
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        self::assertNull($centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre));
    }

    public function testToggleLockLocksTheCentreValue(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $def = $this->definition('reports.title', 'Predeterminado');
        $this->persist($centre, $admin, $def);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'centre'], $this->client);
        $component->call('toggleLock', ['key' => 'reports.title']);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'reports.title']);
        self::assertNotNull($reloadedDef);
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        $stored = $centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre);
        self::assertNotNull($stored);
        self::assertTrue($stored->isLocked());
    }

    public function testGetRowsExposesTheDefinitionsForTheGivenScope(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $def = $this->definition('reports.title', 'Valor por defecto');
        $this->persist($centre, $admin, $def);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'centre'], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringContainsString('reports.title', $html);
    }
}
