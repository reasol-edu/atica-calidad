<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Tests\Integration\ControllerTestCase;

final class EducationalCentreControllerTest extends ControllerTestCase
{
    private function centre(string $code = '12345678', string $name = 'Centro'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName($name)->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function admin(string $username = 'admin'): Teacher
    {
        $teacher = $this->teacher($username);
        $teacher->setAdmin(true);

        return $teacher;
    }

    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    public function testIndexDeniedToANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/admin/centros');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testNewCreatesACentreWithAFreshAcademicYear(): void
    {
        $admin = $this->admin();
        $this->persist($admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/centros/nuevo');
        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => $this->csrfToken('new_centre'),
            'code'   => '87654321',
            'name'   => 'IES Nuevo',
            'city'   => 'Sevilla',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/centros'));

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres  = self::getContainer()->get(EducationalCentreRepository::class);
        $reloaded = $centres->findByCode('87654321');
        self::assertNotNull($reloaded);
        self::assertSame('IES Nuevo', $reloaded->getName());
    }

    public function testNewRejectsADuplicateCode(): void
    {
        $existing = $this->centre('87654321', 'Ya existe');
        $admin    = $this->admin();
        $this->persist($existing, $admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/centros/nuevo');
        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => $this->csrfToken('new_centre'),
            'code'   => '87654321',
            'name'   => 'Otro nombre',
            'city'   => 'Otra ciudad',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        self::assertSame('Ya existe', $centres->findByCode('87654321')?->getName());
    }

    public function testNewRejectsMissingRequiredFields(): void
    {
        $admin = $this->admin();
        $this->persist($admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/centros/nuevo');
        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => $this->csrfToken('new_centre'),
            'code'   => '',
            'name'   => '',
            'city'   => '',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testEditUpdatesFieldsAndAdmins(): void
    {
        $centre  = $this->centre();
        $admin   = $this->admin();
        $newAdmin = $this->teacher('futuro_admin');
        $this->persist($centre, $admin, $newAdmin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('GET', "/admin/centros/{$centreId}");
        $this->client->request('POST', "/admin/centros/{$centreId}", [
            '_token' => $this->csrfToken('edit_centre_' . $centreId),
            'code'   => $centre->getCode(),
            'name'   => 'Nombre actualizado',
            'city'   => $centre->getCity(),
            'admins' => [$newAdmin->getId()->toRfc4122()],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/admin/centros/{$centreId}"));

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres  = self::getContainer()->get(EducationalCentreRepository::class);
        $reloaded = $centres->findById($centreId);
        self::assertNotNull($reloaded);
        self::assertSame('Nombre actualizado', $reloaded->getName());
        self::assertCount(1, $reloaded->getAdmins());
    }

    public function testEditOfAnUnknownCentreIs404(): void
    {
        $admin = $this->admin();
        $this->persist($admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/centros/00000000-0000-0000-0000-000000000000');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesTheCentreAndItsYears(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/centros');
        $this->client->request('POST', "/admin/centros/{$centreId}/eliminar", [
            '_token' => $this->csrfToken('delete_centre_' . $centreId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/centros'));

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        self::assertNull($centres->findById($centreId));
    }

    public function testDeleteRejectedWithInvalidCsrfToken(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('POST', "/admin/centros/{$centreId}/eliminar", [
            '_token' => 'invalido',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        self::assertNotNull($centres->findById($centreId));
    }
}
