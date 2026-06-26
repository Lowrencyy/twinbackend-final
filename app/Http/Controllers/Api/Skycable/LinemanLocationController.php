<?php

namespace App\Http\Controllers\Api\Skycable;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LinemanLocation;
use App\Models\WarehouseReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LinemanLocationController extends Controller
{
    private const ARRIVAL_RADIUS_METERS = 150;

    /**
     * POST skycable/lineman/location
     * Mobile app pings every 10 minutes with GPS + reverse-geocoded address.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'latitude'    => 'required|numeric|between:-90,90',
            'longitude'   => 'required|numeric|between:-180,180',
            'accuracy'    => 'nullable|numeric|min:0',
            'timestamp'   => 'nullable|string',
            'barangay'    => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:255',
            'province'    => 'nullable|string|max:255',
            'region_name' => 'nullable|string|max:255',
            'activity'    => 'nullable|string|max:100',
            'pole_id'     => 'nullable|integer',
            'pole_code'   => 'nullable|string|max:255',
            'node_id'     => 'nullable|integer',
            'node_name'   => 'nullable|string|max:255',
            'area_id'     => 'nullable|integer',
        ]);

        $update = [
            'latitude'    => $data['latitude'],
            'longitude'   => $data['longitude'],
            'accuracy'    => $data['accuracy'] ?? null,
            'barangay'    => $data['barangay'] ?? null,
            'city'        => $data['city'] ?? null,
            'province'    => $data['province'] ?? null,
            'region_name' => $data['region_name'] ?? null,
            'pinged_at'   => Carbon::now(),
        ];

        // Only overwrite activity context when explicitly provided so routine
        // 10-minute pings don't clear the last known activity.
        if (array_key_exists('activity', $data) && $data['activity'] !== null) {
            $update['activity']  = $data['activity'];
            $update['pole_id']   = $data['pole_id']   ?? null;
            $update['pole_code'] = $data['pole_code']  ?? null;
            $update['node_id']   = $data['node_id']   ?? null;
            $update['node_name'] = $data['node_name'] ?? null;
            $update['area_id']   = $data['area_id']   ?? null;
        }

        LinemanLocation::updateOrCreate(
            ['user_id' => $request->user()->id],
            $update
        );

        $arrivedReceiptIds = $this->autoArrivePendingReceipts(
            $request->user()->id,
            (float) $data['latitude'],
            (float) $data['longitude']
        );

        return response()->json(['ok' => true, 'arrived_receipt_ids' => $arrivedReceiptIds]);
    }

    /**
     * GET skycable/lineman/locations
     * Web dashboard polls this — returns linemen active in the last 24 hours
     * with full subcontractor, team, and address details.
     */
    public function index()
    {
        $rows = LinemanLocation::with(['user.subcontractor', 'user.team'])
            ->where('pinged_at', '>=', Carbon::now()->subHours(24))
            ->orderByDesc('pinged_at')
            ->get();

        $now = Carbon::now();

        $data = $rows->map(function ($loc) use ($now) {
            $user     = $loc->user;
            $diffMins = $now->diffInMinutes($loc->pinged_at);

            if ($diffMins <= 15) {
                $status = 'active';
            } elseif ($diffMins <= 45) {
                $status = 'idle';
            } else {
                $status = 'offline';
            }

            $name = $user->full_name
                ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            return [
                'id'         => (string) $user->id,
                'name'       => $name,
                'employeeId' => $user->employee_id
                    ?? 'EMP-' . str_pad($user->id, 4, '0', STR_PAD_LEFT),
                'status'     => $status,
                'lat'        => (float) $loc->latitude,
                'lng'        => (float) $loc->longitude,
                'accuracy'   => $loc->accuracy ? (float) $loc->accuracy : null,
                'pingedAt'   => $loc->pinged_at->toIso8601String(),
                // Address (from mobile reverse geocode)
                'barangay'   => $loc->barangay,
                'city'       => $loc->city,
                'province'   => $loc->province,
                'regionName' => $loc->region_name,
                // Team info
                'teamId'     => $user->team_id,
                'teamName'   => $user->team?->name ?? null,
                // Subcontractor info
                'subconId'   => $user->subcontractor_id,
                'subconName' => $user->subcontractor?->name ?? $user->subcontractor_name ?? null,
                // Activity context (set when lineman starts teardown work)
                'activity'   => $loc->activity,
                'poleId'     => $loc->pole_id,
                'poleCode'   => $loc->pole_code,
                'nodeId'     => $loc->node_id,
                'nodeName'   => $loc->node_name,
                'areaId'     => $loc->area_id,
            ];
        });

        return response()->json($data);
    }

    private function autoArrivePendingReceipts(int $userId, float $lat, float $lng): array
    {
        $arrivedReceiptIds = [];

        WarehouseReceipt::with('warehouse')
            ->where('received_by', $userId)
            ->where('status', 'pending')
            ->get()
            ->each(function (WarehouseReceipt $receipt) use ($lat, $lng, &$arrivedReceiptIds) {
                $warehouse = $receipt->warehouse;
                if (! $warehouse || $warehouse->lat === null || $warehouse->lng === null) {
                    return;
                }

                $distance = $this->distanceMeters($lat, $lng, (float) $warehouse->lat, (float) $warehouse->lng);
                if ($distance > self::ARRIVAL_RADIUS_METERS) {
                    return;
                }

                $old = $receipt->toArray();
                $receipt->status = 'arrived';
                $receipt->save();
                AuditLog::record('update', $receipt, $old, $receipt->fresh()->toArray());
                $arrivedReceiptIds[] = $receipt->id;
            });

        return $arrivedReceiptIds;
    }

    private function distanceMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusM = 6371000;
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $lat1 = deg2rad($fromLat);
        $lat2 = deg2rad($toLat);

        $a = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return $earthRadiusM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
