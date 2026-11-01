<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignFile;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Campaigns run for clients. Clients see their own (read-only); staff manage them. */
class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['planned', 'active', 'paused', 'completed'])],
            'platform' => ['nullable', 'string'],
        ]);
        $list = Campaign::visibleTo($request->user())->with(['client:id,company_name', 'owner:id,name'])
            ->when($f['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['platform'] ?? null, fn ($q, $p) => $q->where('platform', $p))
            ->orderByRaw("case status when 'active' then 0 when 'planned' then 1 when 'paused' then 2 else 3 end")
            ->orderByDesc('start_date')->limit(300)->get();

        return response()->json(['data' => $list->map->toApiArray(), 'platforms' => config('crm.campaign_platforms')]);
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign);

        return response()->json($campaign->load(['client:id,company_name', 'owner:id,name', 'files'])->toApiArray(true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        abort_unless(Client::visibleTo($request->user())->whereKey($data['client_id'])->exists(), 403, 'This client is managed by someone else.');
        $campaign = Campaign::create([...$data, 'owner_id' => $data['owner_id'] ?? $request->user()->id]);

        return response()->json($campaign->load(['client:id,company_name', 'owner:id,name', 'files'])->toApiArray(true), 201);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign);
        $data = $this->validated($request, partial: true);
        unset($data['client_id']);
        $resultFields = ['spend', 'reach', 'impressions', 'clicks', 'leads', 'conversions', 'summary'];
        if (array_intersect(array_keys($data), $resultFields)) {
            $data['results_updated_at'] = now();
        }
        $campaign->update($data);

        return response()->json($campaign->fresh(['client:id,company_name', 'owner:id,name', 'files'])->toApiArray(true));
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign);
        foreach ($campaign->files as $f) {
            Storage::disk('local')->delete($f->path);
        }
        $campaign->delete();

        return response()->json(['deleted' => true]);
    }

    /** Monthly report PDF, creative, screenshot… shared with the client. */
    public function upload(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,webp,xlsx,xls,csv,docx,pptx,zip,mp4'],
            'title' => ['nullable', 'string', 'max:160'],
        ], ['file.mimes' => 'Upload a PDF, image, Excel/CSV, Word, PowerPoint, ZIP or MP4 file.']);
        $file = $request->file('file');

        $campaign->files()->create([
            'title' => $data['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'path' => $file->store("campaigns/{$campaign->id}", 'local'),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($campaign->fresh(['client:id,company_name', 'owner:id,name', 'files'])->toApiArray(true), 201);
    }

    public function deleteFile(Request $request, CampaignFile $file): JsonResponse
    {
        $this->authorizeCampaign($request, $file->campaign);
        Storage::disk('local')->delete($file->path);
        $file->delete();

        return response()->json(['deleted' => true]);
    }

    public function download(Request $request, CampaignFile $file): StreamedResponse
    {
        abort_unless(Campaign::visibleTo($request->user())->whereKey($file->campaign_id)->exists(), 404);

        return Storage::disk('local')->download($file->path, $file->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizeCampaign(Request $request, Campaign $campaign): void
    {
        abort_unless(Campaign::visibleTo($request->user())->whereKey($campaign->id)->exists(), 404);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $count = ['nullable', 'integer', 'min:0', 'max:9999999999'];

        return $request->validate([
            'client_id' => [$req, 'integer', Rule::exists('clients', 'id')],
            'name' => [$req, 'string', 'max:160'],
            'platform' => [$req, Rule::in(array_keys(config('crm.campaign_platforms')))],
            'objective' => ['nullable', 'string', 'max:120'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'paused', 'completed'])],
            'spend' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'reach' => $count, 'impressions' => $count, 'clicks' => $count, 'leads' => $count, 'conversions' => $count,
            'summary' => ['nullable', 'string', 'max:3000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', ['admin', 'employee'])],
        ]);
    }
}
