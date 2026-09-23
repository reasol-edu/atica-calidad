<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whether a password shows up in known data breaches, via the "Have I Been Pwned" range API with
 * k-anonymity: only the first 5 characters of the password's SHA-1 ever leave the server, and the
 * match is done locally against the suffixes returned (padded, so the response size gives nothing
 * away either).
 *
 * Not Symfony's own NotCompromisedPassword constraint: its validator sets no timeout, so on a
 * network that silently drops outgoing traffic (a centre's isolated intranet) a password change
 * would hang for a minute. Here the request is capped at a few seconds and any failure lets the
 * password through (logged) — this is a safety net, never a reason to lock anyone out. Turned off
 * with APP_PASSWORD_BREACH_CHECK=false (always off in tests).
 */
final class CompromisedPasswordChecker
{
    private const string ENDPOINT = 'https://api.pwnedpasswords.com/range/%s';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'bool:APP_PASSWORD_BREACH_CHECK')]
        private readonly bool $enabled,
    ) {}

    public function isCompromised(string $password): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $hash   = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $body = $this->httpClient->request('GET', \sprintf(self::ENDPOINT, $prefix), [
                'headers'      => ['Add-Padding' => 'true'],
                'timeout'      => 3,
                'max_duration' => 5,
            ])->getContent();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Could not check the password against known breaches: {error}', ['error' => $e->getMessage()]);

            return false;
        }

        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            if ($candidate === $suffix && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
