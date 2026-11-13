<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** The status line each card of the Responsabilidades hub shows under its description. */
final class HubStatsTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testResponsibilitiesCardsCountTheirContents(): void
    {
        $centre  = $this->centre();
        $admin   = $this->teacher('director');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin, $profile);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades");
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Ninguna lista todavía.', $html);
        self::assertStringContainsString('1 perfil.', $html);
    }

    /** Utilidades has no tools for now (they moved to another application): empty page, no menu entry. */
    public function testTheEmptyUtilitiesHubIsNotLinkedFromTheMenu(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $centre->getAdmins()->add($teacher);
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $crawler = $this->client->request('GET', '/utilidades');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Por ahora no hay ninguna herramienta disponible.', $crawler->text());
        self::assertCount(0, $crawler->filter('a[href="/utilidades"]'), 'The menu does not link to the empty section.');
    }
}
