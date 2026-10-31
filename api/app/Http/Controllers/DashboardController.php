<?php

namespace App\Http\Controllers;

use App\Services\DashboardOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function overview(Request $request, DashboardOverview $overview): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', Rule::in(DashboardOverview::PERIODS)]]);

        return response()->json($overview->build($data['period'] ?? 'month'));
    }
}
