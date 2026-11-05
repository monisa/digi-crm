<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Settings → Backups (admins only: backups contain everything). */
class BackupController extends Controller
{
    public function __construct(private Backup $backup) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only admins can see backups.');

        return response()->json(['data' => $this->backup->list(), 'keep' => ['database' => Backup::KEEP_DB, 'files' => Backup::KEEP_FILES]]);
    }

    public function run(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only admins can make backups.');
        $kind = $request->validate(['kind' => ['nullable', 'in:database,files']])['kind'] ?? 'database';
        @set_time_limit(300);
        $name = $kind === 'files' ? $this->backup->files() : $this->backup->database();
        abort_unless($name, 422, 'Zip is not available on this server.');

        return response()->json(['name' => $name, 'data' => $this->backup->list()], 201);
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only admins can download backups.');
        $path = $this->backup->path($name);
        abort_unless($path, 404);

        return response()->download($path, $name, ['Content-Type' => str_ends_with($name, '.zip') ? 'application/zip' : 'application/gzip']);
    }
}
