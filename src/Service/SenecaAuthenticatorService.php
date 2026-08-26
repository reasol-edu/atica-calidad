<?php
/*
  Copyright (C) 2018-2025: Luis Ramón López López

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU Affero General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU Affero General Public License for more details.

  You should have received a copy of the GNU Affero General Public License
  along with this program.  If not, see [http://www.gnu.org/licenses/].
*/

declare(strict_types=1);

namespace App\Service;

use phpseclib3\Math\BigInteger;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SenecaAuthenticatorService
{
    public function __construct(
        #[Autowire(env: 'APP_EXTERNAL_URL')]
        private readonly string $url,
        #[Autowire(env: 'bool:APP_EXTERNAL_URL_FORCE_SECURITY')]
        private readonly bool $forceSecurity,
        #[Autowire(env: 'bool:APP_EXTERNAL_ENABLED')]
        private readonly bool $enabled,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        if (!$this->forceSecurity) {
            $this->logger->warning('iSéneca TLS verification is DISABLED — do not use in production');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @throws RandomException
     */
    public function checkUserCredentials(string $user, string $password): bool
    {
        if (!$this->enabled) {
            throw new RuntimeException('External authentication is disabled.');
        }

        $passwordCodificada = '';
        $encodedPassword = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $password);
        $normalizedPassword = is_string($encodedPassword) ? $encodedPassword : $password;

        for ($i = 0, $iMax = strlen($normalizedPassword); $i < $iMax; $i++) {
            $passwordCodificada .= ord($normalizedPassword[$i]);
        }

        $p = new BigInteger($passwordCodificada);

        $passwordCifrada = $p->powMod(
            new BigInteger('3584956249', 10),
            new BigInteger('356056806984207294102423357623243547284021', 10)
        )->toString();

        $fields = ['USUARIO' => $user, 'rndval' => random_int(10000000, 99999999), 'CLAVECIFRADA' => $passwordCifrada, 'CON_PRUEBA' => 'N', 'N_V_' => 'NV_' . random_int(1, 9999), 'NAV_WEB_NOMBRE' => 'Chrome', 'NAV_WEB_VERSION' => '99', 'C_INTERFAZ' => 'PASEN'];
        $str = $this->postToUrl($fields, $this->url, $this->url, $this->forceSecurity);

        if ($str === '') {
            throw new RuntimeException('External authentication service is unavailable.');
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        if (!$dom->loadXML($str, LIBXML_NONET | LIBXML_NOENT)) {
            libxml_use_internal_errors($previous);
            throw new RuntimeException('External authentication service returned an invalid response.');
        }

        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nav = $xpath->query('//correcto');

        $item = $nav !== false ? $nav->item(0) : null;

        return $nav !== false && $nav->length === 1 && $item instanceof \DOMNode && $item->textContent === 'SI';
    }

    /**
     * Gets the content after POSTing into a URL.
     *
     * @param array<string, scalar> $fields
     */
    private function postToUrl(array $fields, string $postUrl, string $refererUrl, bool $forceSecurity): string
    {
        if ($postUrl === '' || $refererUrl === '') {
            return '';
        }

        try {
            $response = $this->httpClient->request('POST', $postUrl, [
                'verify_peer' => $forceSecurity,
                'verify_host' => $forceSecurity,
                'timeout' => 10,
                'max_redirects' => 2,
                'headers' => [
                    'Referer' => $refererUrl,
                    'User-Agent' => 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) ' .
                        'AppleWebKit/533.4 (KHTML, like Gecko) Chrome/5.0.375.125 Safari/533.4',
                ],
                'body' => $fields,
            ]);

            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->error('iSéneca HTTP client error', ['exception' => $e]);

            return '';
        }
    }
}
