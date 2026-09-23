<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CompromisedPasswordChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CompromisedPasswordCheckerTest extends TestCase
{
    private const string PASSWORD = 'contraseña de ejemplo';

    private function suffix(): string
    {
        return substr(strtoupper(sha1(self::PASSWORD)), 5);
    }

    public function testOnlyTheFirstFiveHashCharactersLeaveTheServerAndAMatchIsCompromised(): void
    {
        $requestedUrl = null;
        $client       = new MockHttpClient(function (string $method, string $url) use (&$requestedUrl): MockResponse {
            $requestedUrl = $url;

            return new MockResponse("0000000000000000000000000000000000A:0\r\n" . $this->suffix() . ":7\r\n");
        });

        self::assertTrue((new CompromisedPasswordChecker($client, new NullLogger(), true))->isCompromised(self::PASSWORD));
        self::assertSame('https://api.pwnedpasswords.com/range/' . substr(strtoupper(sha1(self::PASSWORD)), 0, 5), $requestedUrl);
    }

    public function testAPaddingEntryWithCountZeroIsNotAMatch(): void
    {
        $client = new MockHttpClient(new MockResponse($this->suffix() . ":0\r\n"));

        self::assertFalse((new CompromisedPasswordChecker($client, new NullLogger(), true))->isCompromised(self::PASSWORD));
    }

    /** No network, a timeout, an error status: never a reason to refuse a password. */
    public function testAFailedCheckLetsThePasswordThrough(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        self::assertFalse((new CompromisedPasswordChecker($client, new NullLogger(), true))->isCompromised(self::PASSWORD));
    }

    public function testDisabledNeverCallsOut(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \LogicException('No request expected while disabled.');
        });

        self::assertFalse((new CompromisedPasswordChecker($client, new NullLogger(), false))->isCompromised(self::PASSWORD));
    }
}
