<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\SchoolEventRepository;
use App\Tests\Integration\ControllerTestCase;

final class SchoolEventControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
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

    public function testNewDeniedWithoutSectionPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/eventos/nuevo');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testNewCreatesAGeneralEvent(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/eventos/nuevo');
        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $this->csrfToken('new_school_event'),
            'date'       => '2025-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Claustro',
            'scope'      => 'general',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var SchoolEventRepository $events */
        $events = self::getContainer()->get(SchoolEventRepository::class);
        $all    = $events->findAllForAcademicYear($year);
        self::assertCount(1, $all);
        self::assertSame('Claustro', $all[0]->getName());
        self::assertTrue($all[0]->isGeneral());
    }

    public function testNewCreatesARestrictedEventWithProfiles(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $admin   = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $profile, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/eventos/nuevo');
        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $this->csrfToken('new_school_event'),
            'date'       => '2025-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Reunión de departamento',
            'scope'      => 'restricted',
            'profiles'   => [$profile->getId()->toRfc4122()],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var SchoolEventRepository $events */
        $events = self::getContainer()->get(SchoolEventRepository::class);
        $all    = $events->findAllForAcademicYear($year);
        self::assertCount(1, $all);
        self::assertFalse($all[0]->isGeneral());
        self::assertCount(1, $all[0]->getProfileRestrictions());
    }

    public function testNewRejectsARestrictedEventWithNoProfilesSelected(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/eventos/nuevo');
        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $this->csrfToken('new_school_event'),
            'date'       => '2025-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Reunión',
            'scope'      => 'restricted',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var SchoolEventRepository $events */
        $events = self::getContainer()->get(SchoolEventRepository::class);
        self::assertCount(0, $events->findAllForAcademicYear($year));
    }

    public function testNewRejectsAnEndTimeNotAfterStartTime(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/eventos/nuevo');
        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $this->csrfToken('new_school_event'),
            'date'       => '2025-09-15',
            'start_time' => '10:00',
            'end_time'   => '09:00',
            'name'       => 'Evento',
            'scope'      => 'general',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var SchoolEventRepository $events */
        $events = self::getContainer()->get(SchoolEventRepository::class);
        self::assertCount(0, $events->findAllForAcademicYear($year));
    }

    public function testNewRejectsAnInvalidUrl(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/eventos/nuevo');
        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $this->csrfToken('new_school_event'),
            'date'       => '2025-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
            'url'        => 'no-es-una-url',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testNewDeniedWhileViewingAPastYear(): void
    {
        $centre     = $this->centre();
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $pastYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($activeYear);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $activeYear, $pastYear, $admin);

        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);
        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $this->csrfToken('new_school_event'),
            'date'       => '2024-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testEditUpdatesTheEvent(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $event = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Original')
            ->setGeneral(true);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $event, $admin);
        $eventId = $event->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/eventos/{$eventId}/editar");
        $this->client->request('POST', "/eventos/{$eventId}/editar", [
            '_token'     => $this->csrfToken('edit_school_event_' . $eventId),
            'date'       => '2025-09-16',
            'start_time' => '11:00',
            'end_time'   => '12:00',
            'name'       => 'Renombrado',
            'scope'      => 'general',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var SchoolEventRepository $events */
        $events   = self::getContainer()->get(SchoolEventRepository::class);
        $reloaded = $events->findByAcademicYearAndId($year, $eventId);
        self::assertNotNull($reloaded);
        self::assertSame('Renombrado', $reloaded->getName());
        self::assertSame('2025-09-16', $reloaded->getDate()->format('Y-m-d'));
    }

    public function testEditOfAnUnknownEventIs404(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/eventos/00000000-0000-0000-0000-000000000000/editar');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesTheEvent(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $event = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('A borrar')
            ->setGeneral(true);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $event, $admin);
        $eventId = $event->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/eventos/{$eventId}/eliminar", [
            '_token' => $this->csrfToken('delete_school_event_' . $eventId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var SchoolEventRepository $events */
        $events = self::getContainer()->get(SchoolEventRepository::class);
        self::assertNull($events->findByAcademicYearAndId($year, $eventId));
    }

    public function testDeleteRejectedWithInvalidCsrfToken(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $event = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Evento')
            ->setGeneral(true);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $event, $admin);
        $eventId = $event->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/eventos/{$eventId}/eliminar", [
            '_token' => 'invalido',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
