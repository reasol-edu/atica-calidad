<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Teacher;
use App\Service\ActivityLogger;
use App\Service\Backup\AutomaticBackup;
use App\Service\FailedMessages;
use App\Service\SystemStatus;
use App\Service\TestEmailSender;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administración → Estado del sistema: is the worker alive, what failed in the background, does the mail transport work,
 * how much room the data takes. Everything that changes something is a POST with a CSRF token and goes to the activity log.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estado')]
class SystemStatusController extends AbstractController
{
    public function __construct(
        private readonly SystemStatus $status,
        private readonly FailedMessages $failed,
        private readonly AutomaticBackup $automaticBackup,
        private readonly TestEmailSender $testEmail,
        private readonly RateLimiterFactoryInterface $adminTestEmailLimiter,
        private readonly ActivityLogger $activityLog,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_admin_system_status', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        return $this->render('admin/system_status/index.html.twig', [
            'worker'       => $this->status->worker(),
            'storage'      => $this->status->storage(),
            'failed_count' => $this->failed->count(),
            'failed'       => $this->failed->list(),
            'backup'       => $this->automaticBackup->status(),
            'email'        => $user instanceof Teacher ? $user->getEmail() : null,
        ]);
    }

    #[Route('/fallidos/{id}/reintentar', name: 'app_admin_system_failed_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(string $id, Request $request): Response
    {
        $this->requireCsrf('system_failed_' . $id, $request);

        $class = $this->failed->retry($id);
        if ($class === null) {
            $this->addFlash('error', $this->translator->trans('system.failed.gone', [], 'admin'));
        } else {
            $this->activityLog->record('system.failed_retried', ['message' => $class]);
            $this->addFlash('success', $this->translator->trans('system.failed.retried', ['%message%' => $class], 'admin'));
        }

        return $this->redirectToRoute('app_admin_system_status');
    }

    #[Route('/fallidos/{id}/descartar', name: 'app_admin_system_failed_discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(string $id, Request $request): Response
    {
        $this->requireCsrf('system_failed_' . $id, $request);

        $class = $this->failed->discard($id);
        if ($class === null) {
            $this->addFlash('error', $this->translator->trans('system.failed.gone', [], 'admin'));
        } else {
            $this->activityLog->record('system.failed_discarded', ['message' => $class]);
            $this->addFlash('success', $this->translator->trans('system.failed.discarded', ['%message%' => $class], 'admin'));
        }

        return $this->redirectToRoute('app_admin_system_status');
    }

    /** Sends a test e-mail to the administrator's own address only (never to an address from the request), three times per 15 minutes at most. */
    #[Route('/correo-prueba', name: 'app_admin_system_test_email', methods: ['POST'])]
    public function testEmail(Request $request): Response
    {
        $this->requireCsrf('system_test_email', $request);

        $user  = $this->getUser();
        $email = $user instanceof Teacher ? $user->getEmail() : null;
        if ($email === null || $email === '') {
            $this->addFlash('error', $this->translator->trans('system.email.no_address', [], 'admin'));

            return $this->redirectToRoute('app_admin_system_status');
        }

        if (!$this->adminTestEmailLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('system.email.throttled', [], 'admin'));

            return $this->redirectToRoute('app_admin_system_status');
        }

        $error = $this->testEmail->send($email);
        $this->activityLog->record('system.test_email', ['ok' => $error === null]);

        $this->addFlash(
            $error === null ? 'success' : 'error',
            $error === null
                ? $this->translator->trans('system.email.sent', ['%email%' => $email], 'admin')
                : $this->translator->trans('system.email.failed', ['%reason%' => $error], 'admin'),
        );

        return $this->redirectToRoute('app_admin_system_status');
    }

    private function requireCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
