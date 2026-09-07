<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventSubscriber;

use App\Entity\ActivityLog;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ActivityLogRepository;
use App\Security\TeacherAuthenticator;
use App\Tests\Integration\ControllerTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class ActivityLogSubscriberTest extends ControllerTestCase
{
    private string $previousAppLog = 'false';

    protected function setUp(): void
    {
        $this->previousAppLog = (string) ($_SERVER['APP_LOG'] ?? 'false');
        $_ENV['APP_LOG']      = $_SERVER['APP_LOG'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV['APP_LOG'] = $_SERVER['APP_LOG'] = $this->previousAppLog;
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', ucfirst($username))))->setUsername($username);
    }

    private function dispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');

        return $dispatcher;
    }

    private function authenticator(): TeacherAuthenticator
    {
        /** @var TeacherAuthenticator $authenticator */
        $authenticator = self::getContainer()->get(TeacherAuthenticator::class);

        return $authenticator;
    }

    /** @return ActivityLog[] */
    private function logs(): array
    {
        $this->em->clear();
        /** @var ActivityLogRepository $repo */
        $repo = self::getContainer()->get(ActivityLogRepository::class);

        return $repo->createFilteredQuery(['sort' => 'createdAt', 'sortDir' => 'asc'])->getResult();
    }

    /** @param ActivityLog[] $logs */
    private function actionTypes(array $logs): array
    {
        return array_map(static fn (ActivityLog $l): string => $l->getActionType(), $logs);
    }

    public function testRecordsASuccessfulLogin(): void
    {
        $teacher = $this->teacher('docente');
        $this->persist($teacher);

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
        $token   = new UsernamePasswordToken($teacher, 'main', $teacher->getRoles());
        $passport = new SelfValidatingPassport(new UserBadge('docente', static fn (): Teacher => $teacher));

        $this->dispatcher()->dispatch(
            new LoginSuccessEvent($this->authenticator(), $passport, $token, $request, null, 'main'),
        );
        // The subscriber buffers; flush happens on kernel.terminate. Force it via a real request.
        $this->client->request('GET', '/login');

        $logs  = $this->logs();
        self::assertContains('session.login', $this->actionTypes($logs));
        $login = array_values(array_filter($logs, static fn (ActivityLog $l): bool => $l->getActionType() === 'session.login'))[0];
        self::assertSame('docente', $login->getActiveUser()?->getUsername());
        self::assertSame('198.51.100.7', $login->getIp());
    }

    public function testRecordsAFailedLoginWithTheAttemptedUsername(): void
    {
        $request  = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '198.51.100.8']);
        $passport = new SelfValidatingPassport(new UserBadge('intruso'));

        $this->dispatcher()->dispatch(
            new LoginFailureEvent(new BadCredentialsException(), $this->authenticator(), $request, null, 'main', $passport),
        );
        $this->client->request('GET', '/login');

        $logs = $this->logs();
        self::assertContains('session.login_failed', $this->actionTypes($logs));

        $failed = array_values(array_filter($logs, static fn (ActivityLog $l): bool => $l->getActionType() === 'session.login_failed'))[0];
        self::assertSame(['username' => 'intruso'], $failed->getData());
        self::assertNull($failed->getActiveUser());
        self::assertSame('198.51.100.8', $failed->getIp());
    }

    public function testRecordsLogout(): void
    {
        $teacher = $this->teacher('docente');
        $this->persist($teacher);

        $this->loginAs($teacher);
        $this->client->request('GET', '/logout');

        self::assertContains('session.logout', $this->actionTypes($this->logs()));
    }

    public function testDoesNotRecordAPlainAuthenticatedPageView(): void
    {
        $teacher = $this->teacher('docente');
        $this->persist($teacher);

        $this->loginAs($teacher);
        // loginAs already did GET / ; do another read-only navigation
        $this->client->request('GET', '/perfil');

        self::assertNotContains('http.request', $this->actionTypes($this->logs()));
    }

    public function testRecordsAGenericEntryForAWriteRequestEvenWhenDenied(): void
    {
        $teacher = $this->teacher('docente');
        $this->persist($teacher);

        $this->loginAs($teacher);
        // A POST write attempt (even one rejected for a missing CSRF token) is exactly what an
        // audit needs to see.
        $this->client->request('POST', '/curso/año/activo');

        $generic = array_values(array_filter(
            $this->logs(),
            static fn (ActivityLog $l): bool => $l->getActionType() === 'http.request',
        ));
        self::assertCount(1, $generic);
        self::assertSame('app_reset_year', $generic[0]->getRoute());
        self::assertSame('POST', $generic[0]->getMethod());
        self::assertNotNull($generic[0]->getStatusCode());
        self::assertSame('docente', $generic[0]->getActiveUser()?->getUsername());
    }
}
