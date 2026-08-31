<?php

declare(strict_types=1);

namespace App\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Guards against exactly the bug that shipped this file: SettingsComponent's templates referenced
 * `data-controller="setting-save"` for months while `assets/controllers/setting_save_controller.js`
 * simply didn't exist (lost when this app was scaffolded from its sibling project) — every setting
 * save silently did nothing in the browser, and nothing in the PHP test suite could catch it, since
 * the LiveAction it was supposed to call is itself fully covered and works fine in isolation.
 *
 * This scans every Twig template for a Stimulus controller name — either a literal
 * `data-controller="..."` attribute or a `stimulus_controller('name')` Twig call — and asserts a
 * matching file exists under assets/controllers/, using the Symfony UX Asset Mapper's own naming
 * convention (kebab-case name <-> snake_case_controller.js). Vendor-namespaced controllers
 * registered in assets/controllers.json (e.g. Symfony UX's own bundled autocomplete/live
 * controllers) have no local file and are excluded.
 */
final class StimulusControllerFilesExistTest extends TestCase
{
    public function testEveryReferencedControllerHasAMatchingFile(): void
    {
        $projectDir  = dirname(__DIR__, 3);
        $controllers = $this->referencedControllerNames($projectDir . '/templates');

        self::assertNotEmpty($controllers, 'sanity check: the scan itself should find at least the controllers already known to exist');

        $missing = [];
        foreach ($controllers as $name) {
            $file = $projectDir . '/assets/controllers/' . $this->expectedFilename($name);
            if (!is_file($file)) {
                $missing[] = "{$name} -> assets/controllers/" . $this->expectedFilename($name);
            }
        }

        self::assertSame([], $missing, "Templates reference a Stimulus controller with no matching file:\n" . implode("\n", $missing));
    }

    /** @return list<string> deduplicated kebab-case controller names, excluding vendor-namespaced ones (contain '--') */
    private function referencedControllerNames(string $templatesDir): array
    {
        $names = [];

        $finder = (new Finder())->files()->in($templatesDir)->name('*.twig');
        foreach ($finder as $file) {
            $contents = $file->getContents();

            if (preg_match_all('/data-controller="([^"]*)"/', $contents, $matches)) {
                foreach ($matches[1] as $value) {
                    foreach (preg_split('/\s+/', trim($value)) as $name) {
                        if ($name !== '') {
                            $names[$name] = true;
                        }
                    }
                }
            }

            if (preg_match_all('/stimulus_controller\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches)) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return array_values(array_filter($names, static fn (string $name): bool => !str_contains($name, '--')));
    }

    private function expectedFilename(string $kebabName): string
    {
        return str_replace('-', '_', $kebabName) . '_controller.js';
    }
}
