<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventSubscriber;

use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\EventSubscriber\IdleSessionSubscriber;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class IdleSessionSubscriberTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private function loggedInTeacher(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $teacher = (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername('docente');
        $this->persist($centre, $teacher);
        $this->loginAs($teacher, $centre);
    }

    private function timeoutSetting(int $minutes): void
    {
        $definition = (new SettingDefinition())->setKey(IdleSessionSubscriber::SETTING)->setType(SettingType::Integer)
            ->setDefaultValue('120')->setGlobalScope(true)->setMinValue(0)->setMaxValue(1440);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue((string) $minutes));
    }

    public function testLogsOutAfterTheDefaultTwoHoursWithoutActivity(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();

        self::mockTime('2025-10-10 11:01:00');
        $this->client->request('GET', '/perfil');

        self::assertTrue($this->client->getResponse()->isRedirect('/login?sesion=caducada'));
        $this->client->request('GET', '/perfil');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'), 'the session is really gone, not just redirected once');
    }

    public function testActivityWithinTheTimeoutKeepsTheSessionAlive(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();

        foreach (['10:30:00', '11:59:00', '13:30:00'] as $time) {
            self::mockTime('2025-10-10 ' . $time);
            $this->client->request('GET', '/perfil');
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'still active at ' . $time);
        }
    }

    public function testTheTimeoutFollowsTheGlobalSetting(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->timeoutSetting(15);
        $this->loggedInTeacher();

        self::mockTime('2025-10-10 09:16:00');
        $this->client->request('GET', '/perfil');

        self::assertTrue($this->client->getResponse()->isRedirect('/login?sesion=caducada'));
    }

    public function testZeroTurnsItOff(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->timeoutSetting(0);
        $this->loggedInTeacher();

        self::mockTime('2025-10-12 09:00:00');
        $this->client->request('GET', '/perfil');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheLoginPageExplainsWhy(): void
    {
        $this->client->request('GET', '/login?sesion=caducada');

        self::assertStringContainsString('sin actividad', (string) $this->client->getResponse()->getContent());
    }
}
