<?php

namespace App\Http\Controllers;

use App\Services\StatutoryRateService;
use Illuminate\Http\Request;

class StatutoryRateController extends Controller
{
    public function index(Request $request, StatutoryRateService $rates)
    {
        abort_unless($request->user()->isOneOf('admin', 'assistant'), 403);
        $data = $request->validate(['as_of' => 'nullable|date']);

        return $rates->status($data['as_of'] ?? null);
    }

    public function check(Request $request, StatutoryRateService $rates)
    {
        abort_unless($request->user()->role === 'admin', 403);

        return $rates->checkOfficialSources();
    }

    public function cron(Request $request, StatutoryRateService $rates)
    {
        $secret = trim((string) config('services.cron.secret'));
        abort_if($secret === '', 503, 'CRON_SECRET is not configured.');
        abort_unless(
            hash_equals('Bearer '.$secret, (string) $request->header('Authorization')),
            401,
        );

        return $rates->checkOfficialSources();
    }
}
