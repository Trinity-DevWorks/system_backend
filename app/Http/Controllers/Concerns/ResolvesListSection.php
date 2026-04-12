<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesListSection
{
    /**
     * Validate optional `section` query param for index-style endpoints.
     *
     * @param  list<string>  $allowed
     */
    protected function resolveListSection(Request $request, array $allowed): ?string
    {
        $section = $request->query('section');
        if ($section === null || $section === '') {
            return null;
        }
        if (! in_array($section, $allowed, true)) {
            abort(422, 'Invalid section. Allowed: '.implode(', ', $allowed).' (omit section for the default list).');
        }

        return $section;
    }
}
