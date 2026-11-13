<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Teacher;
use App\Service\ActivityLogger;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\DatabaseBackupService;
use App\Service\Backup\RestoreLauncher;
use App\Service\Backup\RestoreState;
use App\Service\Backup\StoredBackup;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backups of the platform, for global administrators only: download one (optionally encrypted), save one
 * on the server and restore one of the saved ones.
 *
 * Restoring replaces ALL the data, so it is guarded: only backups already in the server's folder (nothing
 * is uploaded through the web), the confirmation of the requester's own password plus a typed word, and
 * the restore is done by a separate process with the application in maintenance (see RestoreGuardSubscriber).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/copias')]
class BackupController extends AbstractController
{
    /** The word to type to confirm a restore. */
    public const CONFIRM_WORD = 'RESTAURAR';

    public function __construct(
        private readonly DatabaseBackupService $backup,
        private readonly BackupLibrary $library,
        private readonly RestoreState $restoreState,
        private readonly RestoreLauncher $launcher,
        private readonly ActivityLogger $activityLog,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'app_admin_backups', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/backup/index.html.twig', [
            'copies'       => $this->library->list(),
            'backup_dir'   => $this->library->directory(),
            'can_restore'  => $this->launcher->isAvailable(),
            'last_restore' => $this->restoreState->lastResult(),
        ]);
    }

    /**
     * Downloads a full backup, encrypted with AES-256 when a password is given. A POST with a CSRF token so no
     * link (nor a browser prefetch) can trigger it, and it is recorded in the activity log: the backup holds
     * the documents of every centre and every teacher's password hash.
     */
    #[Route('/descargar', name: 'app_admin_backup_download', methods: ['POST'])]
    public function download(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup_download', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $password = $request->request->getString('password');
        if ($password !== '' && $password !== $request->request->getString('password_confirm')) {
            $this->addFlash('error', $this->translator->trans('backup.download.password_mismatch', [], 'admin'));

            return $this->redirectToRoute('app_admin_backups');
        }

        // With many documents it can take longer than the default limit.
        set_time_limit(0);

        $name = \sprintf('atica-calidad-backup-%s.zip', $this->clock->now()->format('Y-m-d-His'));

        try {
            // A throwaway file in the temp folder (deleted once sent), never in the saved-backups folder.
            $backup = $this->backup->create(sys_get_temp_dir(), 'atica-download-' . bin2hex(random_bytes(6)) . '.zip', $password === '' ? null : $password);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('backup.error', ['%reason%' => $e->getMessage()], 'admin'));

            return $this->redirectToRoute('app_admin_backups');
        }

        $this->activityLog->record('backup.downloaded', [
            'tables'    => $backup->tableCount(),
            'rows'      => $backup->totalRows(),
            'encrypted' => $backup->encrypted,
        ]);

        $response = new BinaryFileResponse($backup->path);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** Saves an unencrypted backup of the current data in the server's folder, to restore it from the web. */
    #[Route('/guardar', name: 'app_admin_backup_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup_save', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        set_time_limit(0);

        try {
            $stored = $this->library->save();
        } catch (BackupException $e) {
            $this->addFlash('error', $this->translator->trans('backup.error', ['%reason%' => $e->getMessage()], 'admin'));

            return $this->redirectToRoute('app_admin_backups');
        }

        $manifest = $stored->manifest;
        $this->activityLog->record('backup.saved', [
            'tables' => $manifest === null ? 0 : $manifest->tableCount(),
            'rows'   => $manifest === null ? 0 : $manifest->totalRows(),
        ]);
        $this->addFlash('success', $this->translator->trans('backup.copies.saved', ['%name%' => $stored->name], 'admin'));

        return $this->redirectToRoute('app_admin_backups');
    }

    /**
     * Downloads a backup kept on the server (to take it to another machine). Same guarantees as downloading
     * a new one: POST with CSRF token, recorded in the activity log, no cache; it is found by its exact name
     * in the folder. It is the file as it is: encrypted if it was made with a password.
     */
    #[Route('/{name}/descargar', name: 'app_admin_backup_download_stored', methods: ['POST'], requirements: ['name' => '[^/]+'])]
    public function downloadStored(string $name, Request $request): Response
    {
        $stored = $this->requireCopy($name);
        if (!$this->isCsrfTokenValid('backup_download_' . $stored->name, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $manifest = $stored->manifest;
        $this->activityLog->record('backup.downloaded', [
            'tables'    => $manifest === null ? 0 : $manifest->tableCount(),
            'rows'      => $manifest === null ? 0 : $manifest->totalRows(),
            'encrypted' => $stored->isEncrypted(),
        ]);

        $response = new BinaryFileResponse($this->library->path($stored));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $stored->name);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/{name}/eliminar', name: 'app_admin_backup_delete', methods: ['POST'], requirements: ['name' => '[^/]+'])]
    public function delete(string $name, Request $request): Response
    {
        $stored = $this->requireCopy($name);
        if (!$this->isCsrfTokenValid('backup_delete_' . $stored->name, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->library->delete($stored->name);
        $this->addFlash('success', $this->translator->trans('backup.copies.deleted', ['%name%' => $stored->name], 'admin'));

        return $this->redirectToRoute('app_admin_backups');
    }

    /** Review before restoring: which backup it is, what is incompatible, what is going to happen. Changes nothing until confirmed. */
    #[Route('/{name}/restaurar', name: 'app_admin_backup_restore', methods: ['GET', 'POST'], requirements: ['name' => '[^/]+'])]
    public function restore(string $name, Request $request): Response
    {
        $stored = $this->requireCopy($name);
        $user   = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->launcher->isAvailable()) {
            $this->addFlash('error', $this->translator->trans('backup.restore.unavailable', [], 'admin'));

            return $this->redirectToRoute('app_admin_backups');
        }
        if (!$stored->isRestorable() || $stored->manifest === null) {
            $this->addFlash('error', $this->translator->trans('backup.restore.not_restorable', [], 'admin'));

            return $this->redirectToRoute('app_admin_backups');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $error = $this->tryRestore($stored, $user, $request);
            if ($error === null) {
                return $this->redirectToRoute('app_admin_backups');
            }
        }

        return $this->render('admin/backup/restore.html.twig', [
            'copy'         => $stored,
            'manifest'     => $stored->manifest,
            'confirm_word' => self::CONFIRM_WORD,
            'error'        => $error,
            'has_password' => $user->getPassword() !== null,
            'user_in_copy' => $this->library->containsUser($stored, $user->getUserIdentifier()),
            'username'     => $user->getUserIdentifier(),
        ]);
    }

    /** @return string|null why it could not be done (already translated), or null if the restore was launched */
    private function tryRestore(StoredBackup $copy, Teacher $user, Request $request): ?string
    {
        $t = fn (string $key): string => $this->translator->trans($key, [], 'admin');

        if (!$this->isCsrfTokenValid('backup_restore_' . $copy->name, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($user->getPassword() === null) {
            return $t('backup.restore.error.no_password');
        }
        if (!$this->passwordHasher->isPasswordValid($user, $request->request->getString('password'))) {
            return $t('backup.restore.error.password');
        }
        if (trim($request->request->getString('confirm')) !== self::CONFIRM_WORD) {
            return $t('backup.restore.error.word');
        }

        // The lock is taken here, before launching the process: the site is already in maintenance when it starts.
        if (!$this->restoreState->acquire($user->getUserIdentifier(), $copy->name)) {
            return $t('backup.restore.error.running');
        }

        try {
            $this->launcher->launch($this->library->path($copy), $user->getUserIdentifier());
        } catch (BackupException $e) {
            // If the process did not start nobody would release the lock and the site would stay in 503.
            $this->restoreState->release();

            return $this->translator->trans('backup.restore.error.launch', ['%reason%' => $e->getMessage()], 'admin');
        }

        $this->logger->warning('Backup restore requested from the web.', ['by' => $user->getUserIdentifier(), 'file' => $copy->name, 'ip' => $request->getClientIp()]);

        return null;
    }

    private function requireCopy(string $name): StoredBackup
    {
        return $this->library->find($name) ?? throw $this->createNotFoundException();
    }
}
