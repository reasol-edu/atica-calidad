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
use App\Entity\TeacherSettingValue;
use App\Service\AppSettings;
use App\Tests\Integration\RepositoryTestCase;

final class AppSettingsTest extends RepositoryTestCase
{
    private AppSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var AppSettings $settings */
        $settings       = self::getContainer()->get(AppSettings::class);
        $this->settings = $settings;
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
        return (new SettingDefinition())->setKey($key)->setType(SettingType::String)->setDefaultValue($default);
    }

    public function testGetForCentreFallsBackToTheDefinitionsDefaultWhenNothingIsStored(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title', 'Informe');
        $this->persist($centre, $def);

        self::assertSame('Informe', $this->settings->getForCentre('reports.title', $centre));
    }

    public function testGetForCentreUsesTheCentreValueWhenSet(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title');
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Personalizado');
        $this->persist($centre, $def, $value);

        self::assertSame('Personalizado', $this->settings->getForCentre('reports.title', $centre));
    }

    /** A locked global value overrides even an explicitly set centre value. */
    public function testALockedGlobalValueOverridesTheCentreValue(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title');
        $global = (new GlobalSettingValue())->setDefinition($def)->setValue('Global bloqueado')->setLocked(true);
        $centreValue = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Centro');
        $this->persist($centre, $def, $global, $centreValue);

        self::assertSame('Global bloqueado', $this->settings->getForCentre('reports.title', $centre));
    }

    public function testAnUnlockedGlobalValueIsOverriddenByACentreValue(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.title');
        $global = (new GlobalSettingValue())->setDefinition($def)->setValue('Global')->setLocked(false);
        $centreValue = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Centro');
        $this->persist($centre, $def, $global, $centreValue);

        self::assertSame('Centro', $this->settings->getForCentre('reports.title', $centre));
    }

    public function testGetForTeacherInCentrePrefersTeacherOverCentreOverGlobalOverDefault(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $def     = $this->definition('ui.greeting', 'Hola');
        $this->persist($centre, $teacher, $def);

        self::assertSame('Hola', $this->settings->getForTeacherInCentre('ui.greeting', $teacher, $centre), 'falls back to default');

        // AppSettings caches its base (definitions + global values) for the lifetime of the
        // instance — the real app re-invalidates after every write (see SettingsComponent), so
        // do the same here between each incremental change.
        $global = (new GlobalSettingValue())->setDefinition($def)->setValue('Global');
        $this->persist($global);
        $this->settings->invalidate();
        self::assertSame('Global', $this->settings->getForTeacherInCentre('ui.greeting', $teacher, $centre));

        $centreValue = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Centro');
        $this->persist($centreValue);
        $this->settings->invalidate();
        self::assertSame('Centro', $this->settings->getForTeacherInCentre('ui.greeting', $teacher, $centre));

        $teacherValue = (new TeacherSettingValue())->setDefinition($def)->setTeacher($teacher)->setValue('Docente');
        $this->persist($teacherValue);
        $this->settings->invalidate();
        self::assertSame('Docente', $this->settings->getForTeacherInCentre('ui.greeting', $teacher, $centre));
    }

    /** A locked centre value overrides the teacher value, even though teacher normally wins over an unlocked centre value. */
    public function testALockedCentreValueOverridesTheTeacherValue(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $def     = $this->definition('ui.greeting');
        $centreValue  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Centro bloqueado')->setLocked(true);
        $teacherValue = (new TeacherSettingValue())->setDefinition($def)->setTeacher($teacher)->setValue('Docente');
        $this->persist($centre, $teacher, $def, $centreValue, $teacherValue);

        self::assertSame('Centro bloqueado', $this->settings->getForTeacherInCentre('ui.greeting', $teacher, $centre));
    }

    public function testGetGlobalIgnoresCentreAndTeacherValues(): void
    {
        $centre  = $this->centre();
        $def     = $this->definition('ui.greeting', 'Predeterminado');
        $global  = (new GlobalSettingValue())->setDefinition($def)->setValue('Global');
        $centreValue = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Centro');
        $this->persist($centre, $def, $global, $centreValue);

        self::assertSame('Global', $this->settings->getGlobal('ui.greeting'));
    }

    public function testBooleanAndIntegerValuesAreCastToTheirNativeType(): void
    {
        $centre    = $this->centre();
        $boolDef   = (new SettingDefinition())->setKey('flag')->setType(SettingType::Boolean)->setDefaultValue('false');
        $intDef    = (new SettingDefinition())->setKey('count')->setType(SettingType::Integer)->setDefaultValue('0');
        $boolValue = (new CentreSettingValue())->setDefinition($boolDef)->setCentre($centre)->setValue('true');
        $intValue  = (new CentreSettingValue())->setDefinition($intDef)->setCentre($centre)->setValue('42');
        $this->persist($centre, $boolDef, $intDef, $boolValue, $intValue);

        self::assertTrue($this->settings->getForCentre('flag', $centre));
        self::assertSame(42, $this->settings->getForCentre('count', $centre));
    }

    public function testUnknownKeyReturnsNull(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        self::assertNull($this->settings->getForCentre('does.not.exist', $centre));
    }

    public function testInvalidateForcesTheNextReadToSeeAFreshlyStoredValue(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('ui.greeting', 'Predeterminado');
        $this->persist($centre, $def);

        self::assertSame('Predeterminado', $this->settings->getForCentre('ui.greeting', $centre));

        $centreValue = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('Nuevo');
        $this->persist($centreValue);

        // Without invalidate(), AppSettings' internal caches would still reflect the old read.
        $this->settings->invalidate();
        self::assertSame('Nuevo', $this->settings->getForCentre('ui.greeting', $centre));
    }
}
