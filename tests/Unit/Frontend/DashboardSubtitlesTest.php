<?php

declare(strict_types=1);

namespace App\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The dashboard picks its subtitle with random(1, N) over the keys home_subtitle_1 … home_subtitle_N.
 * A bound that doesn't match the translation file would show a raw key (N too high) or never show
 * the last phrases (N too low).
 */
final class DashboardSubtitlesTest extends TestCase
{
    public function testTheRandomBoundMatchesTheTranslatedPhrases(): void
    {
        $projectDir = dirname(__DIR__, 3);

        $template = (string) file_get_contents($projectDir . '/templates/dashboard/index.html.twig');
        self::assertSame(1, preg_match("/'home_subtitle_' ~ random\\(1, (\\d+)\\)/", $template, $matches));
        $upper = (int) $matches[1];

        /** @var array<string, string> $messages */
        $messages = Yaml::parseFile($projectDir . '/translations/dashboard.es.yaml');
        $keys     = array_filter(array_keys($messages), static fn (string $key): bool => str_starts_with($key, 'home_subtitle_'));

        self::assertCount($upper, $keys);
        for ($i = 1; $i <= $upper; ++$i) {
            self::assertNotSame('', trim($messages['home_subtitle_' . $i] ?? ''), "home_subtitle_{$i} is missing or empty");
        }
    }
}
