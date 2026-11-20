<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\CentreSettingValueRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Service\SettingsManager;
use App\Service\SettingsSaveOutcome;
use App\Tests\Integration\RepositoryTestCase;

final class SettingsManagerTest extends RepositoryTestCase
{
    private SettingsManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SettingsManager $manager */
        $manager       = self::getContainer()->get(SettingsManager::class);
        $this->manager = $manager;
    }

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

    /** A choice setting whose options are "0" and "1" (documents.min_revision): "0" is a value, not an empty one that resets the setting. */
    public function testAChoiceOfZeroIsSavedAndResolvedAsZero(): void
    {
        $centre = $this->centre();
        $def    = (new SettingDefinition())->setKey('documents.min_revision')->setType(SettingType::Choice)->setChoices('0,1')
            ->setDefaultValue('1')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($centre, $def);

        self::assertSame(SettingsSaveOutcome::Saved, $this->manager->save('global', 'documents.min_revision', '0', null, null));
        self::assertSame(SettingsSaveOutcome::Saved, $this->manager->save('centre', 'documents.min_revision', '1', $centre, null));
        self::assertSame(SettingsSaveOutcome::RejectedInvalid, $this->manager->save('global', 'documents.min_revision', '2', null, null));

        $this->em->clear();
        /** @var GlobalSettingValueRepository $globalValues */
        $globalValues = self::getContainer()->get(GlobalSettingValueRepository::class);
        $stored       = $globalValues->findAllIndexedByKey()['documents.min_revision'] ?? null;
        self::assertNotNull($stored);
        self::assertSame('0', $stored->getValue());
    }

    public function testSaveCreatesACentreValue(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title');
        $this->persist($centre, $def);

        $outcome = $this->manager->save('centre', 'reports.title', 'Nuevo título', $centre, null);

        self::assertSame(SettingsSaveOutcome::Saved, $outcome);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var \App\Repository\SettingDefinitionRepository $definitions */
        $definitions   = self::getContainer()->get(\App\Repository\SettingDefinitionRepository::class);
        $reloadedDef   = $definitions->findOneBy(['key' => 'reports.title']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres        = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        $stored = $centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre);
        self::assertNotNull($stored);
        self::assertSame('Nuevo título', $stored->getValue());
    }

    public function testSaveRejectsAnInvalidValue(): void
    {
        $centre = $this->centre();
        $def    = (new SettingDefinition())->setKey('reports.count')->setType(SettingType::Integer)->setDefaultValue('0')->setCentreScope(true)->setMinValue(1)->setMaxValue(10);
        $this->persist($centre, $def);

        $outcome = $this->manager->save('centre', 'reports.count', '999', $centre, null);

        self::assertSame(SettingsSaveOutcome::RejectedInvalid, $outcome);
    }

    public function testSaveRejectsAnUnknownKey(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $outcome = $this->manager->save('centre', 'does.not.exist', 'valor', $centre, null);

        self::assertSame(SettingsSaveOutcome::RejectedInvalid, $outcome);
    }

    public function testSaveWithAnEmptyValueResetsToDefault(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title');
        $existing = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Personalizado');
        $this->persist($centre, $def, $existing);

        $outcome = $this->manager->save('centre', 'reports.title', '', $centre, null);

        self::assertSame(SettingsSaveOutcome::Saved, $outcome);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var \App\Repository\SettingDefinitionRepository $definitions */
        $definitions   = self::getContainer()->get(\App\Repository\SettingDefinitionRepository::class);
        $reloadedDef   = $definitions->findOneBy(['key' => 'reports.title']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres        = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        self::assertNull($centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre));
    }

    /** A key locked at the global level must refuse a centre-scope save attempt. */
    public function testSaveRejectedWhenTheKeyIsLockedAtAHigherScope(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title');
        $global = (new GlobalSettingValue())->setDefinition($def)->setValue('Bloqueado')->setLocked(true);
        $this->persist($centre, $def, $global);

        $outcome = $this->manager->save('centre', 'reports.title', 'Intento', $centre, null);

        self::assertSame(SettingsSaveOutcome::RejectedLocked, $outcome);
    }

    public function testSaveCreatesATeacherValue(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $def     = $this->definition('ui.greeting');
        $this->persist($centre, $teacher, $def);

        $outcome = $this->manager->save('teacher', 'ui.greeting', 'Hola docente', null, $teacher);

        self::assertSame(SettingsSaveOutcome::Saved, $outcome);
    }

    public function testToggleLockOnAnUnsetCentreValueCreatesALockedEntryWithTheDefault(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title', 'Predeterminado');
        $this->persist($centre, $def);

        $this->manager->toggleLock('centre', 'reports.title', $centre);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var \App\Repository\SettingDefinitionRepository $definitions */
        $definitions   = self::getContainer()->get(\App\Repository\SettingDefinitionRepository::class);
        $reloadedDef   = $definitions->findOneBy(['key' => 'reports.title']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres        = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        $stored = $centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre);
        self::assertNotNull($stored);
        self::assertTrue($stored->isLocked());
        self::assertSame('Predeterminado', $stored->getValue());
    }

    public function testToggleLockOnAnExistingValueFlipsTheFlag(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title');
        $existing = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Valor')->setLocked(false);
        $this->persist($centre, $def, $existing);

        $this->manager->toggleLock('centre', 'reports.title', $centre);

        $this->em->clear();
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        /** @var \App\Repository\SettingDefinitionRepository $definitions */
        $definitions   = self::getContainer()->get(\App\Repository\SettingDefinitionRepository::class);
        $reloadedDef   = $definitions->findOneBy(['key' => 'reports.title']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres        = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        $stored = $centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre);
        self::assertNotNull($stored);
        self::assertTrue($stored->isLocked());

        $this->manager->toggleLock('centre', 'reports.title', $centre);
        $this->em->clear();
        $stored = $centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre);
        self::assertNotNull($stored);
        self::assertFalse($stored->isLocked());
    }

    public function testGetRowsReflectsParentLockOrigin(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title', 'Predeterminado');
        $global = (new GlobalSettingValue())->setDefinition($def)->setValue('Global bloqueado')->setLocked(true);
        $this->persist($centre, $def, $global);

        $rows = $this->manager->getRows('centre', $centre, null);

        self::assertCount(1, $rows);
        self::assertSame('global', $rows[0]['parentLock']);
        self::assertSame('Global bloqueado', $rows[0]['effectiveValue']);
    }
}
