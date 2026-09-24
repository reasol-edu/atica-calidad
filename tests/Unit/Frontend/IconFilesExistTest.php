<?php

declare(strict_types=1);

namespace App\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Production serves icons only from assets/icons/ (config/packages/ux_icons.yaml: iconify off,
 * missing icons ignored), while dev and test fetch any missing one from Iconify on the fly — so an
 * icon used without its file looks fine everywhere but in production, where it silently vanishes.
 * That's how "Mejora continua" shipped with a blank menu icon. This scans templates and PHP for
 * every "heroicons:name" and asserts its SVG is bundled; import a missing one with
 * `bin/console ux:icons:import heroicons:name`.
 */
final class IconFilesExistTest extends TestCase
{
    public function testEveryReferencedIconIsBundled(): void
    {
        $projectDir = dirname(__DIR__, 3);

        $missing = [];
        foreach ((new Finder())->files()->in([$projectDir . '/templates', $projectDir . '/src'])->name(['*.twig', '*.php']) as $file) {
            preg_match_all('/heroicons:([a-z0-9-]+)/', $file->getContents(), $matches);
            foreach ($matches[1] as $icon) {
                if (!is_file($projectDir . '/assets/icons/heroicons/' . $icon . '.svg')) {
                    $missing[$icon] = $file->getRelativePathname();
                }
            }
        }

        self::assertSame([], $missing, 'Icons used but not bundled in assets/icons/heroicons (icon => first file using it).');
    }
}
