<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\CalendarFeedToken;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\CalendarFeedTokenRepository;
use App\Service\CalendarFeedBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A teacher's personal calendar as an iCal feed to subscribe to from Google Calendar, Apple
 * Calendar or a phone. The feed itself is public (a calendar app has no session) and guarded only
 * by the secret token in its address; everything else — seeing the address, generating a new one,
 * turning the feed off — needs the teacher to be logged in and only touches their own token.
 */
class CalendarFeedController extends AbstractController
{
    public function __construct(
        private readonly CalendarFeedTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/calendario/suscripcion', name: 'app_calendar_subscription', methods: ['GET'])]
    public function subscription(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $feed = $this->tokens->findFor($this->requireTeacher(), $centre);

        $httpUrl = $feed !== null ? $this->generateUrl('app_calendar_feed', ['token' => $feed->getToken()], UrlGeneratorInterface::ABSOLUTE_URL) : null;

        return $this->render('calendar/subscription.html.twig', [
            'centre'    => $centre,
            'feed'      => $feed,
            'httpsUrl'  => $httpUrl,
            'webcalUrl' => $httpUrl !== null ? preg_replace('#^https?://#', 'webcal://', $httpUrl) : null,
        ]);
    }

    /** Creates the feed address, or replaces it with a new one (the old address stops working). */
    #[Route('/calendario/suscripcion/generar', name: 'app_calendar_subscription_generate', methods: ['POST'])]
    public function generate(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->requireCsrf($request);
        $teacher = $this->requireTeacher();

        $feed = $this->tokens->findFor($teacher, $centre);
        if ($feed === null) {
            $this->em->persist(new CalendarFeedToken($teacher, $centre, $this->clock->now()));
            $this->addFlash('success', $this->translator->trans('feed.flash.created', [], 'calendar'));
        } else {
            $feed->regenerate($this->clock->now());
            $this->addFlash('success', $this->translator->trans('feed.flash.regenerated', [], 'calendar'));
        }
        $this->em->flush();

        return $this->redirectToRoute('app_calendar_subscription');
    }

    #[Route('/calendario/suscripcion/desactivar', name: 'app_calendar_subscription_disable', methods: ['POST'])]
    public function disable(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->requireCsrf($request);

        $feed = $this->tokens->findFor($this->requireTeacher(), $centre);
        if ($feed !== null) {
            $this->em->remove($feed);
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('feed.flash.disabled', [], 'calendar'));
        }

        return $this->redirectToRoute('app_calendar_subscription');
    }

    /** Public: whoever holds the token reads that teacher's deadlines. Unknown, revoked or inactive → 404. */
    #[Route('/calendario/feed/{token}.ics', name: 'app_calendar_feed', requirements: ['token' => '[0-9a-f]{48}'], methods: ['GET'])]
    public function feed(string $token, CalendarFeedBuilder $builder): Response
    {
        $feed = $this->tokens->findByToken($token);
        if ($feed === null || !$feed->getTeacher()->isActive()) {
            throw $this->createNotFoundException();
        }

        $response = new Response($builder->build($feed->getTeacher(), $feed->getEducationalCentre()));
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        $response->headers->set('Content-Disposition', 'inline; filename="calendario.ics"');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function requireTeacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('calendar_feed', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
