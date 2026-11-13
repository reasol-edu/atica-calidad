<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\RestoreState;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Support\RecordingRestoreLauncher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Download, save and restore backups from the administration panel, and the protection of the application while a restore runs. */
final class BackupControllerTest extends ControllerTestCase
{
    private const PASSWORD = 'Contraseña-segura-1';

    private function library(): BackupLibrary
    {
        /** @var BackupLibrary $library */
        $library = self::getContainer()->get(BackupLibrary::class);

        return $library;
    }

    private function state(): RestoreState
    {
        /** @var RestoreState $state */
        $state = self::getContainer()->get(RestoreState::class);

        return $state;
    }

    private function loginAsAdminWithPassword(): Teacher
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $admin  = (new Teacher(new PersonName('Admin', 'Sys')))->setUsername('admin.restore')->setAdmin(true);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $this->persist($admin);
        $this->loginAs($admin);

        return $admin;
    }

    /** Saves a backup on the server and returns its name. */
    private function aCopy(): string
    {
        return $this->library()->save()->name;
    }

    /** @return array<string, string> the fields of the confirmation form, with the token */
    private function confirmForm(string $name, string $password = self::PASSWORD, string $word = 'RESTAURAR'): array
    {
        $crawler = $this->client->request('GET', '/admin/copias/' . $name . '/restaurar');

        return [
            '_token'   => (string) $crawler->filter('form input[name="_token"]')->last()->attr('value'),
            'password' => $password,
            'confirm'  => $word,
        ];
    }

    private function downloadToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/copias');

        return (string) $crawler->filter('form[action$="/descargar"] input[name="_token"]')->first()->attr('value');
    }

    // ── access ───────────────────────────────────────────────────────────────

    public function testOnlyGlobalAdminsCanDoAnyOfThis(): void
    {
        $name    = $this->aCopy();
        $centre  = (new EducationalCentre())->setCode('41000888')->setName('IES')->setCity('Sevilla');
        $teacher = (new Teacher(new PersonName('Cen', 'Admin')))->setUsername('centre.admin.restore');
        $centre->addAdmin($teacher);
        $this->persist($centre, $teacher);
        $this->loginAs($teacher);

        foreach (['GET /admin/copias', 'GET /admin/copias/' . $name . '/restaurar', 'POST /admin/copias/guardar', 'POST /admin/copias/descargar', 'POST /admin/copias/' . $name . '/eliminar'] as $route) {
            [$method, $path] = explode(' ', $route);
            $this->client->request($method, $path, ['_token' => 'x']);
            self::assertResponseStatusCodeSame(403, $route);
        }
        self::assertSame([], RecordingRestoreLauncher::$launched);
    }

    public function testTheAdministrationHomeLinksToTheBackups(): void
    {
        $this->loginAsAdminWithPassword();

        $crawler = $this->client->request('GET', '/admin');

        self::assertCount(1, $crawler->filter('a[href="/admin/copias"]'));
    }

    // ── download ─────────────────────────────────────────────────────────────

    public function testADownloadIsAZipWithTheWholeDatabase(): void
    {
        $this->loginAsAdminWithPassword();
        $token = $this->downloadToken();

        $this->client->request('POST', '/admin/copias/descargar', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=atica-calidad-backup-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $zip = $this->openDownloadedZip();
        self::assertNotFalse($zip->getFromName('manifest.json'));
        self::assertSame(\ZipArchive::EM_NONE, $zip->statName('manifest.json')['encryption_method'] ?? null);
        self::assertSame([], $this->library()->list(), 'A download is not kept in the saved-backups folder.');
    }

    public function testADownloadWithAPasswordIsEncrypted(): void
    {
        $this->loginAsAdminWithPassword();
        $token = $this->downloadToken();

        $this->client->request('POST', '/admin/copias/descargar', ['_token' => $token, 'password' => 'abre-sesamo', 'password_confirm' => 'abre-sesamo']);

        self::assertResponseIsSuccessful();
        $zip = $this->openDownloadedZip();
        self::assertNotSame(\ZipArchive::EM_NONE, $zip->statName('manifest.json')['encryption_method'] ?? null);
        $zip->setPassword('abre-sesamo');
        self::assertNotFalse($zip->getFromName('manifest.json'), 'It opens with the password.');
    }

    public function testMismatchedPasswordsDownloadNothing(): void
    {
        $this->loginAsAdminWithPassword();
        $token = $this->downloadToken();

        $this->client->request('POST', '/admin/copias/descargar', ['_token' => $token, 'password' => 'one', 'password_confirm' => 'other']);

        self::assertResponseRedirects('/admin/copias');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main, body', 'no coinciden');
    }

    public function testDownloadingNeedsAValidTokenAndNeverWorksByGet(): void
    {
        $this->loginAsAdminWithPassword();

        $this->client->request('POST', '/admin/copias/descargar', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/copias/descargar');
        self::assertResponseStatusCodeSame(405);
    }

    private function openDownloadedZip(): \ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'atica-test-zip-');
        self::assertNotFalse($path);
        file_put_contents($path, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::RDONLY));
        register_shutdown_function(static fn () => @unlink($path));

        return $zip;
    }

    // ── save, list, delete ───────────────────────────────────────────────────

    public function testSavingACopyOnTheServerListsItWithItsRestoreButton(): void
    {
        $this->loginAsAdminWithPassword();
        $crawler = $this->client->request('GET', '/admin/copias');
        $token   = $crawler->filter('form[action$="/guardar"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/copias/guardar', ['_token' => $token]);
        self::assertResponseRedirects('/admin/copias');

        $crawler = $this->client->request('GET', '/admin/copias');
        self::assertSelectorTextContains('main, body', 'atica-calidad-backup-');
        self::assertCount(1, $crawler->filter('a[href*="/copias/"][href$="/restaurar"]'));
    }

    public function testSavingNeedsAValidToken(): void
    {
        $this->loginAsAdminWithPassword();

        $this->client->request('POST', '/admin/copias/guardar', ['_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->library()->list());
    }

    public function testACopyCanBeDeleted(): void
    {
        $this->loginAsAdminWithPassword();
        $name    = $this->aCopy();
        $crawler = $this->client->request('GET', '/admin/copias');
        $token   = $crawler->filter('form[action$="/eliminar"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/copias/' . $name . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects('/admin/copias');
        self::assertSame([], $this->library()->list());
    }

    public function testDeletingNeedsAValidTokenAndAnExistingCopy(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('POST', '/admin/copias/' . $name . '/eliminar', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->library()->list());

        $this->client->request('POST', '/admin/copias/never-existed.zip/eliminar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    // ── download a stored copy ───────────────────────────────────────────────

    public function testAStoredCopyCanBeDownloadedFromTheList(): void
    {
        $this->loginAsAdminWithPassword();
        $name    = $this->aCopy();
        $crawler = $this->client->request('GET', '/admin/copias');
        $token   = $crawler->filter('form[action$="/' . $name . '/descargar"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/copias/' . $name . '/descargar', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=' . $name, (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(file_get_contents($this->library()->directory() . '/' . $name), $this->client->getInternalResponse()->getContent(), 'It is the stored copy, as it is.');
    }

    public function testDownloadingAStoredCopyNeedsAValidTokenAndAnExistingCopy(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('POST', '/admin/copias/' . $name . '/descargar', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/copias/' . $name . '/descargar');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', '/admin/copias/never-existed.zip/descargar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyFilesInsideTheCopiesFolderCanBeDownloaded(): void
    {
        $this->loginAsAdminWithPassword();
        file_put_contents(\dirname($this->library()->directory()) . '/secret.zip', 'x');
        @mkdir($this->library()->directory(), 0o700, true);

        foreach (['../secret.zip', '..%2Fsecret.zip', 'sub%2Fbackup.zip'] as $name) {
            $this->client->request('POST', '/admin/copias/' . $name . '/descargar', ['_token' => 'x']);
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], $name);
        }
        @unlink(\dirname($this->library()->directory()) . '/secret.zip');
    }

    public function testDownloadsAreLoggedInTheActivityLog(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';

        try {
            $this->loginAsAdminWithPassword();
            $token = $this->downloadToken();
            $this->client->request('POST', '/admin/copias/descargar', ['_token' => $token]);
            self::assertResponseIsSuccessful();

            $this->em->clear();
            $actions = array_map(static fn (ActivityLog $log): string => $log->getActionType(), $this->em->getRepository(ActivityLog::class)->findAll());
            self::assertContains('backup.downloaded', $actions);
        } finally {
            putenv('APP_LOG=false');
            $_ENV['APP_LOG']    = 'false';
            $_SERVER['APP_LOG'] = 'false';
        }
    }

    // ── review and confirmation ──────────────────────────────────────────────

    public function testTheReviewPageShowsTheCopyAndWhatWillHappen(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('GET', '/admin/copias/' . $name . '/restaurar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Restaurar una copia de seguridad');
        self::assertSelectorTextContains('main, body', $name);
        self::assertSelectorTextContains('main, body', 'Esto sustituye todos los datos');
        self::assertSelectorExists('input[name="password"]');
        self::assertSelectorExists('input[name="confirm"]');
        // The teacher asking is in the copy (it was saved with them already created): no "user missing" warning.
        self::assertStringNotContainsString('NO figura en esa copia', (string) $this->client->getResponse()->getContent());
        self::assertSame([], RecordingRestoreLauncher::$launched, 'Looking does not restore.');
        self::assertFalse($this->state()->isRunning());
    }

    public function testItWarnsWhenTheCurrentUserIsNotInTheCopy(): void
    {
        $name = $this->aCopy();   // the copy is made before this administrator exists
        $this->loginAsAdminWithPassword();

        $this->client->request('GET', '/admin/copias/' . $name . '/restaurar');

        self::assertSelectorTextContains('main, body', 'NO figura en esa copia');
    }

    public function testAWrongPasswordOrWordDoesNotRestore(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('POST', '/admin/copias/' . $name . '/restaurar', $this->confirmForm($name, 'another-password'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'La contraseña no es correcta');

        $this->client->request('POST', '/admin/copias/' . $name . '/restaurar', $this->confirmForm($name, self::PASSWORD, 'restaurar'));
        self::assertSelectorTextContains('[role="alert"]', 'palabra de confirmación');

        self::assertSame([], RecordingRestoreLauncher::$launched);
        self::assertFalse($this->state()->isRunning(), 'No failed attempt leaves the lock on.');
    }

    public function testAnInvalidTokenIsForbidden(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('POST', '/admin/copias/' . $name . '/restaurar', ['_token' => 'x', 'password' => self::PASSWORD, 'confirm' => 'RESTAURAR']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], RecordingRestoreLauncher::$launched);
    }

    public function testAnAccountWithoutAPasswordCannotConfirmFromTheWeb(): void
    {
        $admin = (new Teacher(new PersonName('Admin', 'Externo')))->setUsername('admin.externo')->setAdmin(true);
        $this->persist($admin);
        $this->loginAs($admin);
        $name = $this->aCopy();

        $crawler = $this->client->request('GET', '/admin/copias/' . $name . '/restaurar');

        self::assertSelectorTextContains('main, body', 'no tiene contraseña propia');
        self::assertCount(0, $crawler->filter('input[name="password"]'));
    }

    // ── launching the restore ────────────────────────────────────────────────

    public function testAValidConfirmationLaunchesTheRestoreAndTheSiteShowsTheRestoringPage(): void
    {
        $admin = $this->loginAsAdminWithPassword();
        $name  = $this->aCopy();

        $this->client->request('POST', '/admin/copias/' . $name . '/restaurar', $this->confirmForm($name));

        self::assertResponseRedirects('/admin/copias');
        self::assertCount(1, RecordingRestoreLauncher::$launched);
        self::assertSame($this->library()->directory() . '/' . $name, RecordingRestoreLauncher::$launched[0]['zip']);
        self::assertSame($admin->getUserIdentifier(), RecordingRestoreLauncher::$launched[0]['by']);
        self::assertTrue($this->state()->isRunning(), 'The web takes the lock before launching the process.');

        // And while it lasts EVERYONE (the requester too) sees the restoring page.
        $this->client->request('GET', '/admin/copias');
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('h1', 'Restaurando una copia de seguridad');
        self::assertSame('15', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringNotContainsString($name, (string) $this->client->getResponse()->getContent(), 'The public page does not say which copy nor who restores.');
        self::assertStringNotContainsString($admin->getUserIdentifier(), (string) $this->client->getResponse()->getContent());
    }

    public function testIfTheProcessCannotBeLaunchedTheLockIsReleased(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        RecordingRestoreLauncher::$failWith = 'proc_open disabled';

        $this->client->request('POST', '/admin/copias/' . $name . '/restaurar', $this->confirmForm($name));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'proc_open disabled');
        self::assertFalse($this->state()->isRunning(), 'If nobody is going to finish it, the site is not left in 503.');
        $this->client->request('GET', '/admin/copias');
        self::assertResponseIsSuccessful();
    }

    public function testItRefusesWhileAnotherRestoreIsRunning(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        $form = $this->confirmForm($name);
        $this->state()->acquire('other', 'x.zip');
        $this->state()->attachPid((int) getmypid());

        // The application is already restoring for everyone: the screen is not even reached.
        $this->client->request('POST', '/admin/copias/' . $name . '/restaurar', $form);

        self::assertResponseStatusCodeSame(503);
        self::assertSame([], RecordingRestoreLauncher::$launched);
    }

    public function testWhereProcessesCannotBeLaunchedOnlyTheConsoleRemains(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        RecordingRestoreLauncher::$available = false;

        $crawler = $this->client->request('GET', '/admin/copias');
        self::assertSelectorTextContains('main, body', 'solo se puede hacer desde la línea de órdenes');
        self::assertCount(0, $crawler->filter('a[href$="/restaurar"]'));

        $this->client->request('GET', '/admin/copias/' . $name . '/restaurar');
        self::assertResponseRedirects('/admin/copias');
    }

    public function testACopyThatCannotBeRestoredHereHasNoRestorePage(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        $zip  = new \ZipArchive();
        $zip->open($this->library()->directory() . '/' . $name);
        $manifest                  = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['schemaVersion'] = 'DoctrineMigrations\\Version20991231000000';
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();

        $crawler = $this->client->request('GET', '/admin/copias');
        self::assertSelectorTextContains('main, body', 'otra versión del esquema');
        self::assertCount(0, $crawler->filter('a[href$="/restaurar"]'));

        $this->client->request('GET', '/admin/copias/' . $name . '/restaurar');
        self::assertResponseRedirects('/admin/copias');
    }

    // ── sessions after a restore ─────────────────────────────────────────────

    public function testASessionOlderThanTheLastRestoreIsClosed(): void
    {
        $this->loginAsAdminWithPassword();
        $this->client->request('GET', '/admin/copias');
        self::assertResponseIsSuccessful();

        // A restore ended a minute after this session was created.
        $this->state()->markRestored();
        $restoredAt = $this->state()->restoredAt();
        self::assertNotNull($restoredAt);
        file_put_contents($this->restoreStateFile('restored-at'), (string) ($restoredAt + 60));

        $this->client->request('GET', '/admin/copias');

        self::assertResponseRedirects('/login');
    }

    private function restoreStateFile(string $name): string
    {
        $dir = self::getContainer()->getParameter('app.restore_state_dir');
        self::assertIsString($dir);

        return $dir . '/' . $name;
    }
}
