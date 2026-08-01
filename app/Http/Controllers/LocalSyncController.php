<?php

namespace App\Http\Controllers;

use App\Models\SyncConflict;
use App\Services\LocalSyncService;
use Illuminate\Http\Request;
use Throwable;

class LocalSyncController extends Controller
{
    public function status(Request $request, LocalSyncService $sync)
    {
        abort_unless($request->user()->isOneOf('admin', 'assistant'), 403);

        return $sync->status();
    }

    public function run(Request $request, LocalSyncService $sync)
    {
        abort_unless($request->user()->isOneOf('admin', 'assistant'), 403);

        try {
            return $sync->run();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json($sync->status(false, 0, 0, 'Cloud synchronization failed: '.$exception->getMessage()), 200);
        }
    }

    public function conflicts(Request $request, LocalSyncService $sync)
    {
        abort_unless($request->user()->role === 'admin', 403);

        return response()->json(['data' => $sync->conflicts()]);
    }

    public function resolveConflict(Request $request, SyncConflict $syncConflict, LocalSyncService $sync)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['action' => 'required|in:retry,discard,accept_remote']);

        return response()->json($sync->resolveConflict($syncConflict, $data['action']));
    }
}
