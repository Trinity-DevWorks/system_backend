<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesShowSection
{
    /**
     * @param  list<string>  $allowed
     */
    protected function resolveShowSection(Request $request, array $allowed, string $default = 'summary'): string
    {
        $section = $request->query('section', $default);
        if (! in_array($section, $allowed, true)) {
            abort(422, 'Invalid section. Allowed: '.implode(', ', $allowed).'.');
        }

        return $section;
    }
}
