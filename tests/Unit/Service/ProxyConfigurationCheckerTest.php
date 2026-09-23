<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ProxyConfigurationChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProxyConfigurationCheckerTest extends TestCase
{
    /** @var list<string> */
    private array $previousProxies;

    private int $previousHeaders;

    protected function setUp(): void
    {
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaders = Request::getTrustedHeaderSet();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaders);
    }

    /** @param list<string> $trusted */
    private function check(string $remote, bool $forwarded, array $trusted = []): ?array
    {
        Request::setTrustedProxies($trusted, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $server = ['REMOTE_ADDR' => $remote];
        if ($forwarded) {
            $server['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        }

        return (new ProxyConfigurationChecker())->check(Request::create('/admin', server: $server));
    }

    public function testAProxyOnTheSameMachineThatIsNotTrustedIsFlaggedWithItsAddress(): void
    {
        self::assertSame(['problem' => ProxyConfigurationChecker::UNTRUSTED_PROXY, 'proxy' => '127.0.0.1'], $this->check('127.0.0.1', forwarded: true));
        self::assertSame(['problem' => ProxyConfigurationChecker::UNTRUSTED_PROXY, 'proxy' => '172.28.0.5'], $this->check('172.28.0.5', forwarded: true));
    }

    public function testATrustedProxyIsFine(): void
    {
        self::assertNull($this->check('127.0.0.1', forwarded: true, trusted: ['127.0.0.1']));
        self::assertNull($this->check('172.28.0.5', forwarded: true, trusted: ['172.28.0.0/24']));
    }

    public function testNoForwardedHeadersMeansNoProxy(): void
    {
        self::assertNull($this->check('127.0.0.1', forwarded: false));
    }

    /** From a public address the headers may be forged by the visitor: never suggest trusting it. */
    public function testForwardedHeadersFromAPublicAddressAreNotFlagged(): void
    {
        self::assertNull($this->check('198.51.99.20', forwarded: true));
    }

    public function testTrustingTheWholeInternetIsFlagged(): void
    {
        self::assertSame(['problem' => ProxyConfigurationChecker::TRUSTS_ANYONE, 'proxy' => '0.0.0.0/0'], $this->check('198.51.99.20', forwarded: true, trusted: ['0.0.0.0/0']));
    }
}
