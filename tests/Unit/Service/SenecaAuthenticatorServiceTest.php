<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SenecaAuthenticatorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SenecaAuthenticatorServiceTest extends TestCase
{
    private function service(string $responseBody): SenecaAuthenticatorService
    {
        return new SenecaAuthenticatorService(
            'https://seneca.example/ComprobarUsuarioExt.jsp',
            true,
            true,
            new MockHttpClient(new MockResponse($responseBody)),
            new NullLogger(),
        );
    }

    public function testAcceptsTheCredentialsWhenTheServiceAnswersSi(): void
    {
        self::assertTrue($this->service('<?xml version="1.0"?><r><correcto>SI</correcto></r>')->checkUserCredentials('usuario', 'secreto'));
    }

    public function testRejectsTheCredentialsWhenTheServiceAnswersNo(): void
    {
        self::assertFalse($this->service('<?xml version="1.0"?><r><correcto>NO</correcto></r>')->checkUserCredentials('usuario', 'secreto'));
    }

    /**
     * A spoofed or hostile response must not get external entities resolved: here the only way
     * to read "SI" is loading a local file through one — which would also be the way to exfiltrate
     * any other file the PHP process can read.
     */
    public function testDoesNotResolveExternalEntitiesInTheResponse(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'seneca');
        self::assertIsString($file);
        file_put_contents($file, 'SI');

        try {
            $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $file . '">]><r><correcto>&x;</correcto></r>';

            self::assertFalse($this->service($xml)->checkUserCredentials('usuario', 'secreto'));
        } finally {
            unlink($file);
        }
    }
}
