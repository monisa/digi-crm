<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    /** Fixed-date national / Kerala holidays the admin can add in one click. Festival dates change every year, so those are added by hand. */
    private const FIXED = [
        '01-26' => 'Republic Day',
        '05-01' => 'May Day',
        '08-15' => 'Independence Day',
        '10-02' => 'Gandhi Jayanti',
        '12-25' => 'Christmas',
    ];

    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? now()->year);

        return response()->json([
            'year' => $year,
            'data' => Holiday::whereYear('date', $year)->orderBy('date')->get()->map->toApiArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $holiday = Holiday::create($this->validated($request));

        return response()->json($holiday->toApiArray(), 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($this->validated($request, $holiday));

        return response()->json($holiday->toApiArray());
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(['deleted' => true]);
    }

    public function addFixed(Request $request): JsonResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100']])['year'];
        $added = 0;
        foreach (self::FIXED as $md => $name) {
            if (! Holiday::where('date', "$year-$md")->exists()) {
                Holiday::create(['date' => "$year-$md", 'name' => $name]);
                $added++;
            }
        }

        return response()->json(['added' => $added]);
    }

    private function validated(Request $request, ?Holiday $holiday = null): array
    {
        return $request->validate([
            'date' => ['required', 'date', Rule::unique('holidays', 'date')->ignore($holiday)],
            'name' => ['required', 'string', 'max:120'],
            'optional' => ['sometimes', 'boolean'],
        ], ['date.unique' => 'There is already a holiday on this date.']);
    }
}
