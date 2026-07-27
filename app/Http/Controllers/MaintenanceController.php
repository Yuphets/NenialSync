<?php

namespace App\Http\Controllers;

use App\Services\MaintenanceModeService;
use App\Services\OfflineOutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MaintenanceController extends Controller
{
    public function status(Request $request, MaintenanceModeService $maintenance)
    {
        $user = $request->user();

        return response()->json([
            ...$maintenance->status(),
            'admin_access' => $user?->role === 'admin' && $user->is_active,
        ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(
        Request $request,
        MaintenanceModeService $maintenance,
        OfflineOutboxService $outbox,
    ) {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'message' => 'nullable|string|max:240',
            'current_password' => 'required|string',
            'confirmation' => 'required|string|max:40',
        ]);
        $expected = $data['enabled'] ? 'START MAINTENANCE' : 'RESTORE WEBSITE';

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The administrator password is incorrect.',
            ]);
        }

        if (! hash_equals($expected, strtoupper(trim($data['confirmation'])))) {
            throw ValidationException::withMessages([
                'confirmation' => "Type {$expected} exactly to confirm this action.",
            ]);
        }

        $status = $maintenance->update(
            (bool) $data['enabled'],
            $data['message'] ?? null,
            $request->user(),
        );
        $outbox->queueMaintenance($status, $request->user());

        return response()->json([
            'message' => $status['enabled']
                ? 'Maintenance mode is active. Only administrators can access the application.'
                : 'The website is open and available again.',
            'maintenance' => $status,
        ]);
    }
}
