<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventSubscriber;

use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Service\MaintenanceMode;
use App\Tests\Integration\ControllerTestCase;

final class MaintenanceModeSubscriberTest extends ControllerTestCase
{
    private function signIn(string $username, bool $admin = false): Teacher
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $teacher = (new Teacher(new PersonName('Nombre', $username)))->setUsername($username)->setAdmin($admin);
        $this->persist($centre, $teacher);
        $this->loginAs($teacher, $centre);

        return $teacher;
    }

    private function maintenance(bool $on): void
    {
        $definition = (new SettingDefinition())->setKey(MaintenanceMode::SETTING)->setType(SettingType::Boolean)
            ->setDefaultValue('false')->setGlobalScope(true);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue($on ? 'true' : 'false'));
    }

    public function testNothingChangesWhileItIsOff(): void
    {
        $this->maintenance(false);
        $this->signIn('docente');

        $this->client->request('GET', '/perfil');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testNothingChangesWhenTheSettingIsNotDefinedAtAll(): void
    {
        $this->signIn('docente');

        $this->client->request('GET', '/perfil');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'a database without the setting must never lock anyone out');
    }

    public function testATeacherSeesTheMaintenancePageWhileItIsOn(): void
    {
        $this->maintenance(true);
        $this->signIn('docente');

        $crawler = $this->client->request('GET', '/perfil');

        self::assertSame(503, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Aplicación en mantenimiento', $crawler->text());
        self::assertStringContainsString('v' . self::getContainer()->getParameter('app.version'), $crawler->text(), 'the footer names the app and its version');
        self::assertSelectorExists('a[href="/logout"]');
    }

    public function testLiveComponentUpdatesAreBlockedToo(): void
    {
        $this->maintenance(true);
        $this->signIn('docente');

        $this->client->request('POST', '/_components/Admin:ListItemTreeComponent/selectItem');

        self::assertSame(503, $this->client->getResponse()->getStatusCode());
    }

    public function testAGlobalAdministratorKeepsWorkingAndIsToldAboutIt(): void
    {
        $this->maintenance(true);
        $this->signIn('director', admin: true);

        $crawler = $this->client->request('GET', '/perfil');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Modo mantenimiento activado', $crawler->text());
    }

    public function testAnAdministratorActingAsATeacherKeepsAccess(): void
    {
        $this->maintenance(true);
        $this->signIn('director', admin: true);
        $this->persist((new Teacher(new PersonName('Otro', 'Docente')))->setUsername('docente'));

        $this->client->request('GET', '/perfil?_switch_user=docente');
        $this->client->request('GET', '/perfil');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheLoginPageStaysOpenAndExplainsIt(): void
    {
        $this->maintenance(true);

        $crawler = $this->client->request('GET', '/login');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('solo pueden entrar los administradores', $crawler->text());
    }

    public function testAnAnonymousVisitorIsSentToTheLogin(): void
    {
        $this->maintenance(true);

        $this->client->request('GET', '/perfil');

        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testThePublicCalendarFeedIsBlocked(): void
    {
        $this->maintenance(true);

        $this->client->request('GET', '/calendario/feed/' . str_repeat('a', 48) . '.ics');

        self::assertSame(503, $this->client->getResponse()->getStatusCode());
    }
}
