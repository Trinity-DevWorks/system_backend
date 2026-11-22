<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\DocumentEmail\Models\EmailSetting;
use App\Modules\DocumentEmail\Services\EmailSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Email: the company mailbox used to email documents. */
class EmailSettingController extends Controller
{
    public function __construct(private readonly EmailSettingService $service) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->service->payload($this->service->settings()), 'Email settings fetched successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255', Rule::requiredIf((bool) $request->boolean('enabled'))],
            // Mail ports only (checked in EmailSettingService::assertHostAllowed; any port in local development).
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(EmailSetting::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['nullable', 'email', 'max:255', Rule::requiredIf((bool) $request->boolean('enabled'))],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'bcc_sender' => ['required', 'boolean'],
        ]);

        return ApiResponse::success($this->service->payload($this->service->update($data)), 'Email settings updated successfully.');
    }

    /** Sends a test email to the given address (default: the signed-in user). Uses the saved settings. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['nullable', 'email', 'max:255']]);
        $user = $request->user();
        $to = (string) ($data['to'] ?? ($user instanceof User ? $user->email : ''));
        if ($to === '') {
            abort(422, 'Enter an address for the test email.', ['X-Error-Code' => 'EMAIL_NO_RECIPIENT']);
        }

        $this->service->sendTest($to);

        return ApiResponse::success($this->service->payload($this->service->settings()), 'Test email sent.');
    }
}
