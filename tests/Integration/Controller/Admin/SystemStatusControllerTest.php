<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\DocumentFile;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Entity\TextIndexStatus;
use App\Message\HeartbeatMessage;
use App\MessageHandler\HeartbeatHandler;
use App\Repository\DocumentFileTextRepository;
use App\Service\DocumentTextIndexer;
use App\Service\SystemState;
use App\Service\SystemStatus;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/** Administración → Estado del sistema: worker heartbeat, failed messages, test e-mail and sizes. */
final class SystemStatusControllerTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var string $dir */
        $dir = self::getContainer()->getParameter('app.state_dir');
        array_map('unlink', glob($dir . '/*.json') ?: []);

        /** @var AdapterInterface $limiters */
        $limiters = self::getContainer()->get('cache.rate_limiter');
        $limiters->clear();
    }

    private function admin(?string $email = 'admin@example.com'): Teacher
    {
        $admin = (new Teacher(new PersonName('Admin', 'Sys')))->setUsername('admin.status')->setAdmin(true)->setEmail($email);
        $this->persist($admin);
        $this->loginAs($admin);

        return $admin;
    }

    private function transport(string $name): TransportInterface
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.' . $name);

        return $transport;
    }

    private function state(): SystemState
    {
        /** @var SystemState $state */
        $state = self::getContainer()->get(SystemState::class);

        return $state;
    }

    private function aFailedMessage(): void
    {
        $this->transport('failed')->send(new Envelope(new HeartbeatMessage(), [new ErrorDetailsStamp(\RuntimeException::class, 0, 'SMTP caído')]));
    }

    /** @return array<string, string> the form fields of the first failed message's buttons */
    private function failedToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/estado');

        return (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
    }

    private function failedId(): string
    {
        $crawler = $this->client->request('GET', '/admin/estado');
        $action  = (string) $crawler->filter('button[formaction*="/reintentar"]')->first()->attr('formaction');
        preg_match('#/fallidos/(\d+)/#', $action, $m);

        return $m[1];
    }

    public function testOnlyGlobalAdminsSeeIt(): void
    {
        $teacher = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('docente.status');
        $this->persist($teacher);
        $this->loginAs($teacher);

        foreach (['GET /admin/estado', 'POST /admin/estado/correo-prueba', 'POST /admin/estado/fallidos/1/reintentar', 'POST /admin/estado/fallidos/1/descartar', 'POST /admin/estado/indice-busqueda/reintentar'] as $route) {
            [$method, $path] = explode(' ', $route);
            $this->client->request($method, $path, ['_token' => 'x']);
            self::assertResponseStatusCodeSame(403, $route);
        }
    }

    public function testTheWorkerIsNeverStaleOrOk(): void
    {
        $this->admin();

        $this->client->request('GET', '/admin/estado');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Todavía no hay señal');

        $this->state()->write(SystemStatus::HEARTBEAT, ['at' => time() - SystemStatus::HEARTBEAT_STALE_AFTER - 60]);
        $this->client->request('GET', '/admin/estado');
        self::assertSelectorTextContains('body', 'no responde desde el');

        $this->state()->write(SystemStatus::HEARTBEAT, ['at' => time()]);
        $this->client->request('GET', '/admin/estado');
        self::assertSelectorTextContains('body', 'funciona: última señal');
    }

    public function testTheHeartbeatHandlerWritesTheTimestamp(): void
    {
        /** @var HeartbeatHandler $handler */
        $handler = self::getContainer()->get(HeartbeatHandler::class);
        $handler(new HeartbeatMessage());

        $beat = $this->state()->read(SystemStatus::HEARTBEAT);
        self::assertNotNull($beat);
        self::assertEqualsWithDelta(time(), $beat['at'], 5);
    }

    public function testTheDashboardCardShowsFailedMessages(): void
    {
        $this->admin();
        $this->aFailedMessage();

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Hay un mensaje fallido');
    }

    public function testAFailedMessageIsListedAndCanBeRetried(): void
    {
        $this->admin();
        $this->aFailedMessage();

        $this->client->request('GET', '/admin/estado');
        self::assertSelectorTextContains('body', 'HeartbeatMessage');
        self::assertSelectorTextContains('body', 'SMTP caído');

        $id    = $this->failedId();
        $token = $this->failedToken();
        $this->client->request('POST', '/admin/estado/fallidos/' . $id . '/reintentar', ['_token' => $token]);
        self::assertResponseRedirects('/admin/estado');

        // It left `failed` and is now waiting for the worker in `async`, not run inside this request.
        $this->client->request('GET', '/admin/estado');
        self::assertSelectorTextContains('body', 'No hay mensajes fallidos');
        $queued = iterator_to_array($this->transport('async')->get());
        self::assertCount(1, $queued);
        self::assertInstanceOf(HeartbeatMessage::class, $queued[0]->getMessage());
    }

    public function testAFailedMessageCanBeDiscarded(): void
    {
        $this->admin();
        $this->aFailedMessage();

        $id    = $this->failedId();
        $token = $this->failedToken();
        $this->client->request('POST', '/admin/estado/fallidos/' . $id . '/descartar', ['_token' => $token]);
        self::assertResponseRedirects('/admin/estado');

        $this->client->request('GET', '/admin/estado');
        self::assertSelectorTextContains('body', 'No hay mensajes fallidos');
        self::assertSame([], iterator_to_array($this->transport('async')->get()));
    }

    public function testRetryNeedsAValidToken(): void
    {
        $this->admin();
        $this->aFailedMessage();
        $id = $this->failedId();

        $this->client->request('POST', '/admin/estado/fallidos/' . $id . '/reintentar', ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, iterator_to_array($this->transport('failed')->get()));
    }

    public function testTheTestEmailGoesOnlyToTheAdminsOwnAddress(): void
    {
        $this->admin('yo@example.com');
        $crawler = $this->client->request('GET', '/admin/estado');
        $token   = (string) $crawler->filter('form[action$="/correo-prueba"] input[name="_token"]')->attr('value');

        // An address in the request is ignored.
        $this->client->request('POST', '/admin/estado/correo-prueba', ['_token' => $token, 'to' => 'otro@example.com', 'email' => 'otro@example.com']);
        self::assertResponseRedirects('/admin/estado');

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', 'yo@example.com');
        self::assertEmailAddressNotContains($email, 'to', 'otro@example.com');
    }

    public function testTheTestEmailIsRateLimited(): void
    {
        $this->admin();
        $crawler = $this->client->request('GET', '/admin/estado');
        $token   = (string) $crawler->filter('form[action$="/correo-prueba"] input[name="_token"]')->attr('value');

        // The mail collector is per request: count the ones that were sent from each answer.
        $sent = 0;
        foreach (range(1, 5) as $i) {
            $this->client->request('POST', '/admin/estado/correo-prueba', ['_token' => $token]);
            $sent += \count(self::getMailerMessages());
        }
        self::assertSame(3, $sent);

        $this->client->request('GET', '/admin/estado');
        self::assertSelectorTextContains('body', 'demasiados correos de prueba');
    }

    public function testWithoutAnAddressThereIsNothingToSendTo(): void
    {
        $this->admin(null);
        $crawler = $this->client->request('GET', '/admin/estado');
        self::assertSelectorNotExists('form[action$="/correo-prueba"] button');
        $token = (string) $crawler->filter('form[action$="/correo-prueba"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/estado/correo-prueba', ['_token' => $token]);
        self::assertEmailCount(0);
    }

    public function testTheTestEmailAndRetriesAreInTheActivityLog(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';

        try {
            $this->admin();
            $this->aFailedMessage();
            $id        = $this->failedId();
            $retryTok  = $this->failedToken();
            $crawler   = $this->client->request('GET', '/admin/estado');
            $emailTok  = (string) $crawler->filter('form[action$="/correo-prueba"] input[name="_token"]')->attr('value');

            $this->client->request('POST', '/admin/estado/correo-prueba', ['_token' => $emailTok]);
            $this->client->request('POST', '/admin/estado/fallidos/' . $id . '/reintentar', ['_token' => $retryTok]);

            $this->em->clear();
            $actions = array_map(static fn (ActivityLog $log): string => $log->getActionType(), $this->em->getRepository(ActivityLog::class)->findAll());
            self::assertContains('system.test_email', $actions);
            self::assertContains('system.failed_retried', $actions);
        } finally {
            putenv('APP_LOG=false');
            $_ENV['APP_LOG']    = 'false';
            $_SERVER['APP_LOG'] = 'false';
        }
    }

    private function fileWithStatus(string $status, string $name): DocumentFile
    {
        $file = new DocumentFile(hash('sha256', $name), $name, 'text/plain', $name . '.txt', \strlen($name));
        $this->persist($file);
        /** @var DocumentFileTextRepository $texts */
        $texts = self::getContainer()->get(DocumentFileTextRepository::class);
        $texts->save($file->getId(), TextIndexStatus::from($status), DocumentTextIndexer::VERSION, null, null, null, new \DateTimeImmutable());

        return $file;
    }

    public function testTheSearchIndexSectionCountsEachStateAndRetriesTheFailedOnes(): void
    {
        $this->fileWithStatus('indexed', 'uno');
        $this->fileWithStatus('failed', 'dos');
        $this->fileWithStatus('failed', 'tres');
        $this->persist(new DocumentFile(hash('sha256', 'sin leer'), 'sin leer', 'text/plain', 'x.txt', 8));

        $this->admin();
        $crawler = $this->client->request('GET', '/admin/estado');
        self::assertSelectorTextContains('body', 'Búsqueda dentro de los documentos');
        self::assertSelectorTextContains('body', 'Pendientes de leer');
        $token = (string) $crawler->filter('form[action$="/indice-busqueda/reintentar"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/estado/indice-busqueda/reintentar', ['_token' => $token]);
        self::assertResponseRedirects('/admin/estado');

        /** @var DocumentFileTextRepository $texts */
        $texts  = self::getContainer()->get(DocumentFileTextRepository::class);
        $counts = $texts->countByStatus(DocumentTextIndexer::VERSION);
        self::assertSame(0, $counts['failed']);
        self::assertSame(1, $counts['indexed']);
        // The two that failed (and the one never read) are pending again.
        self::assertSame(3, $counts['pending']);
    }

    public function testRetryingTheSearchIndexNeedsAValidToken(): void
    {
        $this->fileWithStatus('failed', 'roto');
        $this->admin();

        $this->client->request('POST', '/admin/estado/indice-busqueda/reintentar', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
        /** @var DocumentFileTextRepository $texts */
        $texts = self::getContainer()->get(DocumentFileTextRepository::class);
        self::assertSame(1, $texts->countByStatus(DocumentTextIndexer::VERSION)['failed']);
    }

    public function testStorageFiguresArePresent(): void
    {
        /** @var SystemStatus $status */
        $status  = self::getContainer()->get(SystemStatus::class);
        $storage = $status->storage();

        self::assertArrayHasKey('database', $storage);
        self::assertGreaterThanOrEqual(0, $storage['backups']);
        self::assertGreaterThanOrEqual(0, $storage['backup_count']);

        $this->admin();
        $this->client->request('GET', '/admin/estado');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Espacio libre');
    }
}
