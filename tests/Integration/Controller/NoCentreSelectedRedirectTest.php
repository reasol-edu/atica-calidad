<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use App\ValueResolver\NoCentreSelectedException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A `#[CurrentCentre]` page must never 500 just because the selected centre vanished from the
 * session mid-session (the centre was deleted, the tenant key was dropped, an old cookie points
 * nowhere). Every such case has to land on the centre-selection screen — or, when the teacher
 * only has one centre anyway, silently recover onto it.
 */
final class NoCentreSelectedRedirectTest extends ControllerTestCase
{
    private function centre(string $code = '11111111', string $name = 'Centro'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName($name)->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', ucfirst($username))))->setUsername($username);
    }

    private function setSessionCentreId(string $id): void
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('tenant.centre_id', $id);
        $session->save();
    }

    public function testDeletedCentreMidSessionRedirectsToSelectionWhenSeveralAreAccessible(): void
    {
        // Three centres so that removing one still leaves the admin with a choice (with exactly
        // one left, TenantContextSubscriber would silently re-select it instead — see below).
        $doomed = $this->centre('11111111', 'A punto de borrarse');
        $other  = $this->centre('22222222', 'El otro');
        $third  = $this->centre('33333333', 'Y un tercero');
        $admin  = $this->teacher('root')->setAdmin(true);
        $this->persist($doomed, $other, $third, $admin);
        $doomedId = $doomed->getId()->toRfc4122();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/');            // materialise the session
        $this->setSessionCentreId($doomedId);

        // The centre disappears while the teacher keeps browsing.
        $this->em->remove($this->em->getRepository(EducationalCentre::class)->find($doomedId));
        $this->em->flush();

        $this->client->request('GET', '/actividades');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertLessThan(500, $status, 'a stale centre must not 500');
        self::assertTrue(
            $this->client->getResponse()->isRedirect('/seleccion/centro'),
            'stale centre with several accessible → centre selection',
        );
    }

    public function testUnknownCentreIdInSessionRedirectsToSelection(): void
    {
        $centreA = $this->centre('11111111', 'Uno');
        $centreB = $this->centre('22222222', 'Dos');
        $admin   = $this->teacher('root')->setAdmin(true);
        $this->persist($centreA, $centreB, $admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/');
        $this->setSessionCentreId(Uuid::v7()->toRfc4122()); // valid UUID, never a centre

        $this->client->request('GET', '/actividades');

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->client->getResponse()->isRedirect('/seleccion/centro'));
    }

    public function testGarbageCentreIdInSessionRedirectsToSelection(): void
    {
        $centreA = $this->centre('11111111', 'Uno');
        $centreB = $this->centre('22222222', 'Dos');
        $admin   = $this->teacher('root')->setAdmin(true);
        $this->persist($centreA, $centreB, $admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/');
        $this->setSessionCentreId('not-a-uuid-at-all'); // e.g. a cookie from an old build

        $this->client->request('GET', '/actividades');

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->client->getResponse()->isRedirect('/seleccion/centro'));
    }

    public function testDeletedCentreMidSessionRecoversSilentlyWhenOnlyOneIsAccessible(): void
    {
        $doomed  = $this->centre('11111111', 'Único (se borra)');
        $survivor = $this->centre('22222222', 'Único de verdad');
        $teacher = $this->teacher('docente');
        $survivor->getAdmins()->add($teacher);
        $this->persist($doomed, $survivor, $teacher);
        $doomedId = $doomed->getId()->toRfc4122();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/');
        $this->setSessionCentreId($doomedId);

        $this->em->remove($this->em->getRepository(EducationalCentre::class)->find($doomedId));
        $this->em->flush();

        $this->client->request('GET', '/actividades');

        // Only one centre left for this teacher → TenantContextSubscriber re-selects it, no error.
        self::assertLessThan(400, $this->client->getResponse()->getStatusCode());
    }

    public function testExpiredSessionOnACurrentCentreRouteRedirectsToLogin(): void
    {
        // No authentication at all — the firewall must win before any centre resolution.
        $this->client->request('GET', '/actividades');

        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testNoCentreSelectedExceptionIsAlwaysTurnedIntoARedirect(): void
    {
        $kernel     = self::getContainer()->get('kernel');
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $event = new ExceptionEvent(
            $kernel,
            Request::create('/algo-con-centro'),
            HttpKernelInterface::MAIN_REQUEST,
            new NoCentreSelectedException(),
        );

        $dispatcher->dispatch($event, 'kernel.exception');

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/seleccion/centro', $response->getTargetUrl());
    }
}
