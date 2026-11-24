<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use App\Models\Central\Plan;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdatePlanRequest extends StorePlanRequest
{
    protected function uniqueName(): Unique
    {
        $plan = $this->route('plan');

        return Rule::unique('plans', 'name')->ignore($plan instanceof Plan ? $plan->id : null);
    }
}
