<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\EducationalCentre;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\RestoreState;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** `app:restore`: lock, previous backup, result, sessions and the emergency exit (`--unlock`). */
final class RestoreCommandLockTest extends RepositoryTestCase
{
    private BackupLibrary $library;
    private RestoreState $state;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BackupLibrary $library */
        $library = self::getContainer()->get(BackupLibrary::class);
        /** @var RestoreState $state */
        $state = self::getContainer()->get(RestoreState::class);
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');

        $this->library = $library;
        $this->state   = $state;
        $this->db      = $db;
    }

    /** @param array<string, mixed> $input */
    private function restoreWith(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:restore'));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function centres(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM educational_centre');
    }

    /** A backup with one centre, taken before a second one appears. Returns the backup's path. */
    private function oneCopyAndOneMoreCentre(): string
    {
        $this->persist((new EducationalCentre())->setCode('41000001')->setName('IES Uno')->setCity('Sevilla'));
        $copy = $this->library->save();
        $this->persist((new EducationalCentre())->setCode('41000002')->setName('IES Dos')->setCity('Cádiz'));
        self::assertSame(2, $this->centres());

        return $this->library->path($copy);
    }

    public function testItRestoresRecordsTheResultAndClosesTheSessions(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();

        $tester = $this->restoreWith(['archive' => $path, '--force' => true, '--by' => 'ana']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, $this->centres(), 'The state of the backup is back.');
        self::assertFalse($this->state->isRunning(), 'The lock is released when it ends.');
        self::assertNotNull($this->state->restoredAt(), 'It is noted so earlier sessions are closed.');
        $result = $this->state->lastResult();
        self::assertTrue($result['ok'] ?? false);
        self::assertSame('ana', $result['by']);
    }

    public function testWithoutForceAndWithoutInteractionItRefuses(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();

        $tester = $this->restoreWith(['archive' => $path]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertSame(2, $this->centres(), 'Nothing changes.');
        self::assertNull($this->state->restoredAt());
    }

    public function testItRefusesWhileAnotherRestoreIsRunningAndDoesNotRemoveItsLock(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();
        $this->state->acquire('other', 'x.zip');
        $this->state->attachPid((int) getmypid());

        $tester = $this->restoreWith(['archive' => $path, '--force' => true]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertSame(2, $this->centres());
        self::assertTrue($this->state->isRunning(), 'It does not remove the lock of whoever is really restoring.');
    }

    public function testALockHeldByTheWebIsReleasedEvenIfTheCopyIsRejected(): void
    {
        // Not a zip: validation fails, but the lock the web took must be released (or the site stays in 503).
        @mkdir($this->library->directory(), 0o700, true);
        $bad = $this->library->directory() . '/bad.zip';
        file_put_contents($bad, 'this is not a zip');
        $this->state->acquire('ana', 'bad.zip');

        $tester = $this->restoreWith(['archive' => $bad, '--lock-held' => true, '--by' => 'ana']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertFalse($this->state->isRunning());
        self::assertFalse($this->state->lastResult()['ok'] ?? true);
        self::assertNull($this->state->restoredAt(), 'A failed restore does not close sessions.');
    }

    public function testAFailedRestoreAfterTheValidationKeepsTheDataAndReleasesTheLock(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();
        $zip  = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('tables/educational_centre.ndjson', "{this is not json\n");
        $zip->close();
        $this->state->acquire('ana', 'backup.zip');

        $tester = $this->restoreWith(['archive' => $path, '--lock-held' => true, '--by' => 'ana']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertSame(2, $this->centres(), 'The transaction undoes the emptying.');
        self::assertFalse($this->state->isRunning());
        self::assertFalse($this->state->lastResult()['ok'] ?? true);
        self::assertNull($this->state->restoredAt());
    }

    public function testTheWebLaunchRestoresWithoutAskingAndWithoutOverridingTheSchema(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();
        $this->state->acquire('ana', 'backup.zip');

        $tester = $this->restoreWith(['archive' => $path, '--lock-held' => true, '--by' => 'ana']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, $this->centres());
        self::assertFalse($this->state->isRunning());
    }

    public function testThePreviousBackupIsSavedBeforeRestoring(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();

        $this->restoreWith(['archive' => $path, '--force' => true, '--previous-backup' => true]);

        $names = array_map(static fn ($c): string => $c->name, $this->library->list());
        self::assertCount(2, $names);
        self::assertNotEmpty(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'atica-calidad-before-restore-')));
    }

    public function testUnlockRemovesAStuckLock(): void
    {
        $this->state->acquire('ana', 'backup.zip');
        $this->state->attachPid((int) getmypid());
        self::assertTrue($this->state->isRunning());

        $tester = $this->restoreWith(['--unlock' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFalse($this->state->isRunning());
        self::assertSame(0, $this->restoreWith(['--unlock' => true])->getStatusCode(), 'With no lock it is not an error either.');
    }

    public function testWithoutAnArchiveItAsksForOne(): void
    {
        self::assertNotSame(0, $this->restoreWith([])->getStatusCode());
    }
}
