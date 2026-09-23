<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Service\ActivityDeadlineChecker;
use App\Service\AppSettingsInterface;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * ActivityDeadlineChecker resolving its academic year start through the real settings engine
 * (global → centre → default), rather than a stub — see ActivityDeadlineCheckerTest for the
 * anchoring rules themselves.
 */
final class ActivityDeadlineCheckerSettingsTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private function checker(): ActivityDeadlineChecker
    {
        return new ActivityDeadlineChecker(self::getContainer()->get('clock'), self::getContainer()->get(AppSettingsInterface::class));
    }

    private function definition(): SettingDefinition
    {
        return (new SettingDefinition())->setKey(ActivityDeadlineChecker::START_DATE_SETTING)->setType(SettingType::DayMonth)
            ->setDefaultValue('09-15')->setCategory('settings.category.academic_year')->setGlobalScope(true)->setCentreScope(true);
    }

    /** An Oct 1–31 activity, looked at on Aug 15 2026 — which academic year that is depends on the setting. */
    private function octoberActivity(EducationalCentre $centre): Activity
    {
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 10)->setEnd(31, 10);
        $this->persist($category, $activity);

        return $activity;
    }

    private function centre(string $code): EducationalCentre
    {
        $centre = (new EducationalCentre())->setCode($code)->setName('Centro ' . $code)->setCity('Ciudad');
        $this->persist($centre);

        return $centre;
    }

    public function testUsesTheDefinitionDefaultWhenNothingIsConfigured(): void
    {
        self::mockTime('2026-08-15 10:00:00');
        $this->persist($this->definition());
        $activity = $this->octoberActivity($this->centre('11111111'));

        self::assertSame('2025-10-31', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
    }

    public function testFallsBackToTheBuiltInDefaultWhenTheSettingIsNotDefinedAtAll(): void
    {
        self::mockTime('2026-08-15 10:00:00');
        $activity = $this->octoberActivity($this->centre('11111111'));

        self::assertSame('2025-10-31', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
    }

    public function testEachCentreUsesItsOwnConfiguredStart(): void
    {
        self::mockTime('2026-08-15 10:00:00');
        $definition = $this->definition();
        $early      = $this->centre('11111111');
        $default    = $this->centre('22222222');
        $this->persist($definition, (new CentreSettingValue())->setDefinition($definition)->setCentre($early)->setValue('08-01'));

        $checker = $this->checker();
        self::assertSame('2026-10-31', $checker->currentCycleEndDate($this->octoberActivity($early))->format('Y-m-d'));
        self::assertSame('2025-10-31', $checker->currentCycleEndDate($this->octoberActivity($default))->format('Y-m-d'));
    }

    public function testALockedGlobalValueOverridesTheCentreOne(): void
    {
        self::mockTime('2026-08-15 10:00:00');
        $definition = $this->definition();
        $centre     = $this->centre('11111111');
        $this->persist(
            $definition,
            (new GlobalSettingValue())->setDefinition($definition)->setValue('08-01')->setLocked(true),
            (new CentreSettingValue())->setDefinition($definition)->setCentre($centre)->setValue('09-15'),
        );

        self::assertSame('2026-10-31', $this->checker()->currentCycleEndDate($this->octoberActivity($centre))->format('Y-m-d'));
    }
}
