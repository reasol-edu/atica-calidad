<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ActivityChangeRepository;
use App\Tests\Integration\ControllerTestCase;

final class ActivityBulkEditControllerTest extends ControllerTestCase
{
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

    /** @return array{EducationalCentre, Teacher, ActivityCategory, ActivityCategory, Activity, Activity} */
    private function scenario(): array
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $from   = (new ActivityCategory())->setEducationalCentre($centre)->setName('Origen');
        $to     = (new ActivityCategory())->setEducationalCentre($centre)->setName('Destino');
        $a      = (new Activity())->setCategory($from)->setTitle('Alfa')->setStart(1, 9)->setEnd(30, 6);
        $b      = (new Activity())->setCategory($from)->setTitle('Beta')->setStart(1, 9)->setEnd(30, 6)->setPosition(1);
        $admin  = (new Teacher(new PersonName('Admin', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->persist($centre, $from, $to, $a, $b, $admin);
        $this->loginAs($admin, $centre);

        return [$centre, $admin, $from, $to, $a, $b];
    }

    public function testThePreviewChangesNothing(): void
    {
        [, , , $to, $a] = $this->scenario();
        $this->client->request('GET', '/actividades/edicion-en-bloque');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/actividades/edicion-en-bloque/vista-previa', [
            '_token' => $this->csrfToken('activity_bulk_edit'),
            'action' => 'move', 'category' => $to->getId()->toRfc4122(), 'ids' => [$a->getId()->toRfc4122()],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Destino', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame('Origen', $this->em->find(Activity::class, $a->getId())?->getCategory()->getName());
    }

    public function testApplyingMovesTheActivitiesAndLeavesAHistoryEntry(): void
    {
        [, , , $to, $a, $b] = $this->scenario();
        $this->client->request('GET', '/actividades/edicion-en-bloque');

        $this->client->request('POST', '/actividades/edicion-en-bloque/aplicar', [
            '_token' => $this->csrfToken('activity_bulk_edit'),
            'action' => 'move', 'category' => $to->getId()->toRfc4122(), 'ids' => [$a->getId()->toRfc4122(), $b->getId()->toRfc4122()],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        $moved = $this->em->find(Activity::class, $a->getId());
        self::assertNotNull($moved);
        self::assertSame('Destino', $moved->getCategory()->getName());
        self::assertNotSame($moved->getPosition(), $this->em->find(Activity::class, $b->getId())?->getPosition(), 'each one gets its own place');

        /** @var ActivityChangeRepository $history */
        $history = self::getContainer()->get(ActivityChangeRepository::class);
        $entries = $history->findByActivity($moved);
        self::assertCount(1, $entries);
        self::assertSame('category', $entries[0]->getChanges()[0]['field']);
        self::assertSame(['Origen', 'Destino'], [$entries[0]->getChanges()[0]['from'], $entries[0]->getChanges()[0]['to']]);
    }

    public function testHidingAndTheDeadlineAreValidated(): void
    {
        [, , , , $a] = $this->scenario();
        $this->client->request('GET', '/actividades/edicion-en-bloque');
        $token = $this->csrfToken('activity_bulk_edit');

        $this->client->request('POST', '/actividades/edicion-en-bloque/aplicar', [
            '_token' => $token, 'action' => 'deadline', 'startDay' => '1', 'startMonth' => '13', 'endDay' => '1', 'endMonth' => '1', 'ids' => [$a->getId()->toRfc4122()],
        ]);
        $this->em->clear();
        self::assertSame(9, $this->em->find(Activity::class, $a->getId())?->getStartMonth(), 'an invalid month changes nothing');

        $this->client->request('POST', '/actividades/edicion-en-bloque/aplicar', [
            '_token' => $this->csrfToken('activity_bulk_edit'), 'action' => 'visibility', 'visibility' => 'hidden', 'ids' => [$a->getId()->toRfc4122()],
        ]);
        $this->em->clear();
        self::assertTrue($this->em->find(Activity::class, $a->getId())?->isHidden());
    }

    public function testAnActivityOfAnotherCentreIsNeverTouched(): void
    {
        [, , , $to] = $this->scenario();
        $otherCentre = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Ciudad');
        $otherCat    = (new ActivityCategory())->setEducationalCentre($otherCentre)->setName('Ajena');
        $foreign     = (new Activity())->setCategory($otherCat)->setTitle('Ajena')->setStart(1, 9)->setEnd(30, 6);
        $this->persist($otherCentre, $otherCat, $foreign);
        $this->client->request('GET', '/actividades/edicion-en-bloque');

        $this->client->request('POST', '/actividades/edicion-en-bloque/aplicar', [
            '_token' => $this->csrfToken('activity_bulk_edit'),
            'action' => 'move', 'category' => $to->getId()->toRfc4122(), 'ids' => [$foreign->getId()->toRfc4122()],
        ]);

        $this->em->clear();
        self::assertSame('Ajena', $this->em->find(Activity::class, $foreign->getId())?->getCategory()->getName());
    }

    public function testAPlainTeacherCannotUseIt(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $teacher = (new Teacher(new PersonName('Doc', 'Docente')))->setUsername('docente');
        $this->persist($centre, $teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/edicion-en-bloque');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
