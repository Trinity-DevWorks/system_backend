<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\CompanySetting\DTOs\CountryResponseData;
use App\Modules\CompanySetting\Support\CountryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locale = is_string($request->query('locale'))
            ? (string) $request->query('locale')
            : 'en';

        return ApiResponse::success(
            CountryResponseData::collectionToArray(CountryCatalog::listForLocale($locale)),
            'Countries fetched successfully.'
        );
    }
}
