<?php

namespace App\Http\Controllers;

use App\Services\LocalSyncService;
use Illuminate\Http\Request;

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

        return $sync->run();
    }
}
