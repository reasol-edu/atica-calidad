<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ActivityLogRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Repository\TeacherRepository;
use App\Service\Backup\AutomaticBackup;
use App\Service\FailedMessages;
use App\Service\ProxyConfigurationChecker;
use App\Service\SystemStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly EducationalCentreRepository $centres,
        private readonly ProxyConfigurationChecker $proxyChecker,
        private readonly AutomaticBackup $automaticBackup,
        private readonly SystemStatus $systemStatus,
        private readonly FailedMessages $failedMessages,
        private readonly ActivityLogRepository $activityLog,
        private readonly GlobalSettingValueRepository $globalSettings,
    ) {}

    #[Route('/admin', name: 'app_admin')]
    public function index(Request $request): Response
    {
        return $this->render('admin/index.html.twig', [
            'proxyWarning' => $this->proxyChecker->check($request),
            'backup'       => $this->automaticBackup->status(),
            'worker'       => $this->systemStatus->worker(),
            'failed_count' => $this->failedMessages->count(),
            'log_latest'   => $this->activityLog->latestCreatedAt(),
            'settings_set' => $this->globalSettings->countAll(),
            'stats'       => [
                'teachers_total'  => $this->teachers->countAll(),
                'teachers_active' => $this->teachers->countActive(),
                'teachers_admin'  => $this->teachers->countAdmins(),
                'centres_total'   => $this->centres->countAll(),
            ],
        ]);
    }
}
