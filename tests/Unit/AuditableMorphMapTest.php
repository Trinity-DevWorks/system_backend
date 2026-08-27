<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Relations\Relation;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class AuditableMorphMapTest extends TestCase
{
    public function test_every_auditable_model_is_registered_in_the_morph_map(): void
    {
        $mappedClasses = array_values(Relation::morphMap() ?? []);
        $missing = [];

        foreach ($this->auditableModelClasses() as $class) {
            if (! in_array($class, $mappedClasses, true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Auditable models missing from Relation::enforceMorphMap: '.implode(', ', $missing)
        );
    }

    /**
     * @return list<class-string>
     */
    private function auditableModelClasses(): array
    {
        $classes = [];
        $finder = Finder::create()->files()->in(app_path())->name('*.php');

        foreach ($finder as $file) {
            $src = $file->getContents();
            if (! preg_match('/implements\s+[^{]*AuditableContract/', $src)) {
                continue;
            }
            if (! preg_match('/^namespace\s+(.+?);/m', $src, $namespaceMatch)) {
                continue;
            }
            if (! preg_match('/^class\s+(\w+)/m', $src, $classMatch)) {
                continue;
            }

            $class = $namespaceMatch[1].'\\'.$classMatch[1];
            if (! class_exists($class) || ! is_subclass_of($class, AuditableContract::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return array_values(array_unique($classes));
    }
}
