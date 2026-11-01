<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Brand files and shared logins that came in with the onboarding form. */
class ClientAssetsController extends Controller
{
    /** Shows the client's shared passwords. Admins, or people given Clients → approve. Every view is logged. */
    public function credentials(Request $request, Client $client): JsonResponse
    {
        abort_unless(Client::visibleTo($request->user())->whereKey($client->id)->exists(), 403);

        DB::table('credential_views')->insert([
            'client_id' => $client->id, 'user_id' => $request->user()->id, 'ip' => $request->ip(), 'viewed_at' => now(),
        ]);

        return response()->json([
            'credentials' => $client->credentials ?? [],
            'views' => DB::table('credential_views')->join('users', 'users.id', '=', 'credential_views.user_id')
                ->where('credential_views.client_id', $client->id)->orderByDesc('viewed_at')->limit(10)
                ->get(['users.name', 'credential_views.viewed_at']),
        ]);
    }

    public function files(Request $request, Client $client): JsonResponse
    {
        abort_unless(Client::visibleTo($request->user())->whereKey($client->id)->exists(), 403);

        return response()->json(['data' => $client->files->map->toApiArray()]);
    }

    public function download(Request $request, ClientFile $file): StreamedResponse
    {
        abort_unless(Client::visibleTo($request->user())->whereKey($file->client_id)->exists(), 404);

        // Always as a download (never shown inline), so an uploaded SVG/HTML cannot run in the CRM.
        return Storage::disk('local')->download($file->path, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
