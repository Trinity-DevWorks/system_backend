<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Requests;

use App\Modules\Notification\Support\NotificationChannels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates bulk preference updates for the notification settings UI.
 *
 * What: Ensures each preference row has a known type + channel and a boolean enabled flag.
 * Used for: PUT /notifications/preferences.
 * Solves: Rejects unknown types/channels before they hit NotificationPreferenceService.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $types = array_keys(config('notifications.types', []));

        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.type' => ['required', 'string', Rule::in($types)],
            'preferences.*.channel' => ['required', 'string', Rule::in(NotificationChannels::preferenceChannels())],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
