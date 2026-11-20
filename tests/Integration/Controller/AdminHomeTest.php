<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\ActivityLog;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** The cards of Administración: every one says something about its own state, not just what it is for. */
final class AdminHomeTest extends ControllerTestCase
{
    private function admin(): Teacher
    {
        $admin = (new Teacher(new PersonName('Admin', 'Global')))->setUsername('admin.home')->setAdmin(true);
        $this->persist($admin);
        $this->loginAs($admin);

        return $admin;
    }

    public function testTheActivityLogCardSaysWhenTheLastEntryWasWrittenOrThatThereIsNone(): void
    {
        $this->admin();
        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('body', 'Aún no hay ningún registro.');

        $this->persist(new ActivityLog(new \DateTimeImmutable('2026-03-04 10:15:00'), '203.0.113.9', 'auth.login'));
        $this->persist(new ActivityLog(new \DateTimeImmutable('2026-03-02 08:00:00'), '203.0.113.9', 'auth.login'));
        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('body', 'Último registro: 04/03/2026 10:15');
    }

    public function testTheSettingsCardSaysEverythingIsDefaultWhenNothingWasChanged(): void
    {
        $this->admin();
        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('body', 'Todos los ajustes tienen su valor por defecto.');
    }

    public function testTheSettingsCardCountsTheSettingsThatHaveAValueOfTheirOwn(): void
    {
        $one = (new SettingDefinition())->setKey('a.one')->setType(SettingType::String)->setDefaultValue('x')->setGlobalScope(true);
        $two = (new SettingDefinition())->setKey('a.two')->setType(SettingType::String)->setDefaultValue('x')->setGlobalScope(true);
        $this->persist($one, $two, (new GlobalSettingValue())->setDefinition($one)->setValue('y'), (new GlobalSettingValue())->setDefinition($two)->setValue('z'));

        $this->admin();
        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('body', '2 ajustes con valor propio.');
    }

    public function testTheSettingsCardUsesTheSingularForOne(): void
    {
        $one = (new SettingDefinition())->setKey('a.one')->setType(SettingType::String)->setDefaultValue('x')->setGlobalScope(true);
        $this->persist($one, (new GlobalSettingValue())->setDefinition($one)->setValue('y'));

        $this->admin();
        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('body', '1 ajuste con valor propio.');
    }
}
