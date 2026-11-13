<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Backup;

use App\Service\Backup\RestoreState;
use PHPUnit\Framework\TestCase;

/** Lock and result of a restore, kept in files. */
final class RestoreStateTest extends TestCase
{
    private string $dir;
    private RestoreState $state;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/atica-restore-state-' . bin2hex(random_bytes(5));
        $this->state = new RestoreState($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testNothingIsRunningAtFirst(): void
    {
        self::assertFalse($this->state->isRunning());
        self::assertNull($this->state->restoredAt());
        self::assertNull($this->state->lastResult());
    }

    public function testALockCanBeTakenOnceAndReleased(): void
    {
        self::assertTrue($this->state->acquire('admin', 'backup.zip'));
        self::assertTrue($this->state->isRunning());
        self::assertFalse($this->state->acquire('other', 'other.zip'), 'There cannot be two restores at once.');
        self::assertSame('admin', $this->state->lockInfo()['by']);

        $this->state->release();

        self::assertFalse($this->state->isRunning());
        self::assertTrue($this->state->acquire('other', 'other.zip'));
    }

    public function testALiveProcessKeepsTheLock(): void
    {
        $this->state->acquire('admin', 'backup.zip');
        $this->state->attachPid((int) getmypid());

        self::assertTrue($this->state->isRunning());
    }

    public function testALockWhoseProcessDiedIsAbandoned(): void
    {
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('Without the posix extension it cannot be told whether a process is alive.');
        }

        $this->state->acquire('admin', 'backup.zip');
        // A PID that does not exist (above the usual maximum of the systems).
        $this->state->attachPid(4_194_000);

        self::assertFalse($this->state->isRunning(), 'A failure must not leave the site in 503 for good.');
        self::assertFalse(is_file($this->dir . '/restore.lock'), 'And the abandoned lock is cleaned up.');
    }

    public function testALockThatNeverGotAProcessExpiresAfterAMinute(): void
    {
        $this->writeLock(['started' => time() - 30, 'pid' => 0, 'by' => 'a', 'file' => 'f.zip']);
        self::assertTrue($this->state->isRunning(), 'Just launched: the child process may still be starting.');

        $this->writeLock(['started' => time() - 120, 'pid' => 0, 'by' => 'a', 'file' => 'f.zip']);
        self::assertFalse($this->state->isRunning(), 'If it did not start within a minute, it never will.');
    }

    public function testAVeryOldLockIsAbandonedEvenWithALivePid(): void
    {
        $this->writeLock(['started' => time() - 5 * 3600, 'pid' => (int) getmypid(), 'by' => 'a', 'file' => 'f.zip']);

        self::assertFalse($this->state->isRunning());
    }

    public function testForceUnlockRemovesAStuckLock(): void
    {
        $this->state->acquire('admin', 'backup.zip');

        self::assertTrue($this->state->forceUnlock());
        self::assertFalse($this->state->isRunning());
        self::assertFalse($this->state->forceUnlock(), 'With no lock there is nothing to remove.');
    }

    public function testTheLastResultAndTheRestoredTimeArePersisted(): void
    {
        $this->state->writeResult(['ok' => true, 'by' => 'admin', 'file' => 'backup.zip']);
        $this->state->markRestored();

        $result = $this->state->lastResult();
        self::assertNotNull($result);
        self::assertTrue($result['ok']);
        self::assertSame('backup.zip', $result['file']);
        self::assertIsInt($result['at']);
        self::assertEqualsWithDelta(time(), $this->state->restoredAt(), 2);
    }

    /** @param array<string, mixed> $info */
    private function writeLock(array $info): void
    {
        @mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/restore.lock', json_encode($info));
    }
}
