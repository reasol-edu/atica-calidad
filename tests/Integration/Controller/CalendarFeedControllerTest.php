<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
use App\Entity\CalendarFeedToken;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\CalendarFeedTokenRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class CalendarFeedControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    /** @return array{Teacher, EducationalCentre} a teacher with one activity to hand in (due 30 June) */
    private function scenario(): array
    {
        self::mockTime('2025-10-10 10:00:00');
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro, de prueba')->setCity('Ciudad');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Programación; anual')->setStart(1, 9)->setEnd(30, 6)
            ->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $teacher = (new Teacher(new PersonName('Nombre', 'docente')))->setUsername('docente');
        $this->persist($centre, $category, $section, $folder, $profile, $activity, $teacher, new SpecificProfileAssignment($profile, null, $teacher));

        return [$teacher, $centre];
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

    public function testTheFeedListsTheTeachersDeadlinesWithoutALogin(): void
    {
        [$teacher, $centre] = $this->scenario();
        $feed = new CalendarFeedToken($teacher, $centre, new \DateTimeImmutable());
        $this->persist($feed);

        $this->client->request('GET', '/calendario/feed/' . $feed->getToken() . '.ics');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('text/calendar', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $body);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20260630', $body);
        self::assertStringContainsString('SUMMARY:Plazo: Programación\; anual', $body);
        self::assertStringContainsString('X-WR-CALNAME:ÁTICA Calidad · Centro\, de prueba', $body);
    }

    public function testAnUnknownTokenIs404(): void
    {
        $this->client->request('GET', '/calendario/feed/' . str_repeat('a', 48) . '.ics');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnInactiveTeachersFeedIs404(): void
    {
        [$teacher, $centre] = $this->scenario();
        $teacher->setActive(false);
        $feed = new CalendarFeedToken($teacher, $centre, new \DateTimeImmutable());
        $this->persist($feed);

        $this->client->request('GET', '/calendario/feed/' . $feed->getToken() . '.ics');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testGeneratingCreatesTheAddressAndRegeneratingRevokesTheOldOne(): void
    {
        [$teacher, $centre] = $this->scenario();
        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/calendario/suscripcion');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/calendario/suscripcion/generar', ['_token' => $this->csrfToken('calendar_feed')]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        /** @var CalendarFeedTokenRepository $tokens */
        $tokens = self::getContainer()->get(CalendarFeedTokenRepository::class);
        $this->em->clear();
        $first = $tokens->findFor($this->em->getRepository(Teacher::class)->findOneBy(['username' => 'docente']) ?? $teacher, $centre);
        self::assertNotNull($first);
        $oldToken = $first->getToken();

        $this->client->request('POST', '/calendario/suscripcion/generar', ['_token' => $this->csrfToken('calendar_feed')]);
        $this->em->clear();
        self::assertNull($tokens->findByToken($oldToken));

        $this->client->request('GET', '/calendario/feed/' . $oldToken . '.ics');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDisablingDeletesTheToken(): void
    {
        [$teacher, $centre] = $this->scenario();
        $feed = new CalendarFeedToken($teacher, $centre, new \DateTimeImmutable());
        $this->persist($feed);
        $token = $feed->getToken();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/calendario/suscripcion');
        $this->client->request('POST', '/calendario/suscripcion/desactivar', ['_token' => $this->csrfToken('calendar_feed')]);

        /** @var CalendarFeedTokenRepository $tokens */
        $tokens = self::getContainer()->get(CalendarFeedTokenRepository::class);
        $this->em->clear();
        self::assertNull($tokens->findByToken($token));
    }

    public function testTheSubscriptionPageNeedsALogin(): void
    {
        $this->client->request('GET', '/calendario/suscripcion');

        self::assertTrue($this->client->getResponse()->isRedirect());
    }
}
