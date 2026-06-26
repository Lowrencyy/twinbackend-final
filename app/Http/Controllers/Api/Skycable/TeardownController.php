<?php

namespace App\Http\Controllers\Api\Skycable;

use App\Http\Concerns\CachesSkycableResponses;
use App\Http\Concerns\StoresPhotos;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PoleCableSlot;
use App\Models\PoleTeardownImage;
use App\Models\SkycableSpan;
use App\Models\SkycableSpanSummary;
use App\Models\SkycableTeardownPhoto;
use App\Models\SkycableTeardownReport;
use App\Models\SkycableTeardownReportSlot;
use App\Models\SkycableNode;
use App\Models\SkycableArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeardownController extends Controller
{
    use CachesSkycableResponses;
    use StoresPhotos;
    public function index(Request $request)
    {
        $perPage  = min((int) ($request->per_page ?? 50), 500);
        $user = $request->user();
        $role = strtolower($user->role ?? '');
        $isWarehouseOrAdmin = str_contains($role, 'admin') 
            || str_contains($role, 'executive') 
            || str_contains($role, 'warehouse')
            || str_contains($role, 'exec');

        // Use role-scoped cache key to prevent data mixing between different authorization levels
        $cacheScope = 'teardowns.index.' . ($isWarehouseOrAdmin ? 'all' : ($user->subcontractor_id ? "sub_{$user->subcontractor_id}" : "team_{$user->team_id}"));

        $hasFilter = $request->hasAny(['span_id', 'node_id', 'team_id', 'status', 'date', 'end_time']);

        $fetch = function () use ($request, $perPage, $user, $isWarehouseOrAdmin) {
            return SkycableTeardownReport::with(['span.node', 'span.fromPole.pole', 'span.toPole.pole', 'span.summary', 'team.subcontractor', 'lineman', 'subconReviewer', 'backendApprover', 'photos'])
                ->when(!$isWarehouseOrAdmin && $user->subcontractor_id, function ($q) use ($user) {
                    $q->whereHas('team', function ($sq) use ($user) {
                        $sq->where('subcontractor_id', $user->subcontractor_id);
                    });
                })
                ->when(!$isWarehouseOrAdmin && !$user->subcontractor_id && $user->team_id, function ($q) use ($user) {
                    $q->where('team_id', $user->team_id);
                })
                ->when($request->span_id, fn ($q) => $q->where('span_id', $request->span_id))
                ->when($request->node_id, fn ($q) => $q->whereHas('span', fn ($sq) => $sq->where('node_id', $request->node_id)))
                ->when($request->team_id, fn ($q) => $q->where('team_id', $request->team_id))
                ->when($request->status, fn ($q) => $q->where('status', $request->status))
                ->when($request->date, fn ($q) => $q->where(function ($query) use ($request) {
                    $query->whereDate('start_time', $request->date)
                        ->orWhereDate('end_time', $request->date);
                }))
                ->when($request->end_time, fn ($q) => $q->whereDate('end_time', $request->end_time))
                ->latest()
                ->paginate($perPage);
        };

        return $this->skycableCachedJson($cacheScope, $hasFilter ? 45 : 60, $fetch, $request);
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'span_id'            => 'required|exists:skycable_spans,id',
            'team_id'            => 'required|exists:teams,id',
            'start_time'         => 'required|date',
            'expected_cable'     => 'nullable|numeric|min:0',
            'offline_mode'       => 'nullable|boolean',
            'captured_at_device' => 'nullable|date',
            'captured_lat'       => 'nullable|numeric',
            'captured_lng'       => 'nullable|numeric',
        ]);

        $data['lineman_id']        = $request->user()->id;
        $data['status']            = 'pending';
        $data['received_at_server'] = now();

        $report = SkycableTeardownReport::create($data);
        AuditLog::record('create', $report, null, $report->toArray());
        $this->bumpSkycableCacheVersion();

        return response()->json($report, 201);
    }

    public function submit(Request $request, SkycableTeardownReport $report)
    {
        if ($report->lineman_id !== $request->user()->id && ! in_array($request->user()->role, ['admin', 'executive', 'project_manager'])) {
            return response()->json(['message' => 'You can only submit your own teardown reports.'], 403);
        }

        $data = $request->validate([
            'end_time'            => 'required|date|after:' . $report->start_time,
            'actual_cable'        => 'nullable|numeric|min:0',
            'nodes_collected'     => 'nullable|integer|min:0',
            'amplifiers_collected'=> 'nullable|integer|min:0',
            'extenders_collected' => 'nullable|integer|min:0',
            'tsc_collected'       => 'nullable|integer|min:0',
            // from-pole photos
            'from_before_photo'   => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            'from_after_photo'    => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            'from_pole_tag_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            // to-pole photos
            'to_before_photo'     => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            'to_after_photo'      => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            'to_pole_tag_photo'   => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            // span-wide
            'bunching_photo'      => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            'slots'               => 'nullable|array',
            'slots.*.pole_id'           => 'required|exists:poles,id',
            'slots.*.pole_cable_slot_id' => 'required|exists:pole_cable_slots,id',
            'slots.*.slot_label'        => 'required|string',
            'notes'               => 'nullable|string',
        ]);

        // Build structured photo paths: teardown/{area}/{node}/{pole_code}_{type}.jpg
        $span         = $report->span()->with(['node.area', 'fromPole.pole', 'toPole.pole'])->first();
        $areaSlug     = Str::slug($span?->node?->area?->name ?? 'unknown');
        $nodeSlug     = Str::slug($span?->node?->name ?? 'unknown');
        $fromPoleCode = Str::slug($span?->fromPole?->pole?->pole_code ?? 'pole');
        $toPoleCode   = Str::slug($span?->toPole?->pole?->pole_code ?? 'pole');

        // map field name → [photo_type enum, structured file path]
        $photoFieldMap = [
            'from_before_photo'   => ['from_before',   "teardown/{$areaSlug}/{$nodeSlug}/{$fromPoleCode}_before.jpg"],
            'from_after_photo'    => ['from_after',    "teardown/{$areaSlug}/{$nodeSlug}/{$fromPoleCode}_after.jpg"],
            'from_pole_tag_photo' => ['from_pole_tag', "teardown/{$areaSlug}/{$nodeSlug}/{$fromPoleCode}_pole_tag.jpg"],
            'to_before_photo'     => ['to_before',     "teardown/{$areaSlug}/{$nodeSlug}/{$toPoleCode}_before.jpg"],
            'to_after_photo'      => ['to_after',      "teardown/{$areaSlug}/{$nodeSlug}/{$toPoleCode}_after.jpg"],
            'to_pole_tag_photo'   => ['to_pole_tag',   "teardown/{$areaSlug}/{$nodeSlug}/{$toPoleCode}_pole_tag.jpg"],
            'bunching_photo'      => ['bunching',      "teardown/{$areaSlug}/{$nodeSlug}/bunching.jpg"],
        ];

        $areaName  = $this->sanitizePath($span?->node?->area?->name ?? 'Unknown_Area');
        $nodeName  = $this->sanitizePath($span?->node?->name ?? 'Unknown_Node');
        $fromPole  = $span?->fromPole?->pole;
        $toPole    = $span?->toPole?->pole;
        $spanId    = $span?->id ?? 'unknown';

        DB::transaction(function () use ($request, $report, $data, $photoFieldMap, $span, $areaName, $nodeName, $fromPole, $toPole, $spanId) {
            foreach ($photoFieldMap as $field => [$photoType, $_]) {
                if ($request->hasFile($field)) {
                    $isBunching = $field === 'bunching_photo';
                    $isToPole   = str_starts_with($field, 'to_');
                    $activePole = $isToPole ? $toPole : $fromPole;
                    $poleCode   = $this->sanitizePath($activePole?->pole_code ?? 'unknown');
                    $poleId     = $activePole?->id ?? 0;

                    // Determine image type slug: before | after | poletag | bunching
                    if ($isBunching) {
                        $typeSlug = 'bunching';
                    } elseif (str_contains($field, 'pole_tag')) {
                        $typeSlug = 'poletag';
                    } elseif (str_contains($field, 'after')) {
                        $typeSlug = 'after';
                    } else {
                        $typeSlug = 'before';
                    }

                    // Build flat path
                    // Pole:    {area}/{node}/{pole_code}/{pole_code}_{type}.jpg
                    // Bunching: spans/{span_id}/{span_id}_bunching.jpg
                    if ($isBunching) {
                        $filePath = "spans/{$spanId}/{$spanId}_bunching.jpg";
                    } else {
                        $filePath = "{$areaName}/{$nodeName}/{$poleCode}/{$poleCode}_{$typeSlug}.jpg";
                    }

                    $path = $this->storePhoto($request->file($field), 'teardown', 1280, $filePath);
                    $data[$field] = $path;

                    SkycableTeardownPhoto::updateOrCreate(
                        ['teardown_report_id' => $report->id, 'photo_type' => $photoType],
                        ['image_path' => $path]
                    );

                    PoleTeardownImage::create([
                        'report_id'      => $report->id,
                        'pole_id'        => $poleId,
                        'area_id'        => $span?->node?->area_id,
                        'node_id'        => $span?->node_id,
                        'pole_code'      => $rawPoleCode !== '' ? $rawPoleCode : ($activePole?->pole_code ?? 'unknown'),
                        'image_type'     => $typeSlug,
                        'file_path'      => $path,
                        'inventory_type' => 'skycable',
                    ]);
                }
            }

            if (! empty($data['slots'])) {
                foreach ($data['slots'] as $slot) {
                    SkycableTeardownReportSlot::create(array_merge($slot, ['teardown_report_id' => $report->id]));

                    // Mark the physical slot as pending teardown so it shows correctly on all clients
                    PoleCableSlot::where('id', $slot['pole_cable_slot_id'])
                        ->update(['status' => 'pending_teardown']);
                }
                unset($data['slots']);
            }

            // remove photo keys — they are stored in skycable_teardown_photos, not as columns
            foreach (array_keys($photoFieldMap) as $field) {
                unset($data[$field]);
            }

            $data['status'] = 'submitted';
            $old = $report->toArray();
            $report->update($data);
            $this->completeSpanFromTeardown($span, $request->user()->id, $data);
            AuditLog::record('update', $report, $old, $report->toArray());
        });

        $this->bumpSkycableCacheVersion();

        return response()->json($report->fresh()->load(['slots', 'photos']));
    }

    public function show(SkycableTeardownReport $report)
    {
        return $this->skycableCachedJson("teardowns.show.{$report->id}", 120, function () use ($report) {
            $report->load(['span.node', 'span.fromPole.pole', 'span.toPole.pole', 'span.summary', 'team', 'lineman', 'slots', 'photos']);

            // If SkycableTeardownPhoto is empty, fall back to PoleTeardownImage records
            if ($report->photos->isEmpty()) {
                $fromPoleCode = $report->span?->fromPole?->pole?->pole_code;
                $toPoleCode   = $report->span?->toPole?->pole?->pole_code;

                $typeMap = ['before' => 'before', 'after' => 'after', 'poletag' => 'pole_tag', 'pole_tag' => 'pole_tag', 'bunching' => 'bunching'];

                $fallback = \App\Models\PoleTeardownImage::where('report_id', $report->id)
                    ->where('inventory_type', 'skycable')
                    ->where(function ($query) use ($fromPoleCode, $toPoleCode) {
                        $query->whereIn('pole_code', array_filter([$fromPoleCode, $toPoleCode]))
                              ->orWhere('image_type', 'bunching');
                    })
                    ->get()
                    ->map(function ($img) use ($fromPoleCode, $toPoleCode, $typeMap) {
                        $base   = $typeMap[$img->image_type] ?? $img->image_type;
                        $prefix = match (true) {
                            $img->pole_code === $toPoleCode   => 'to_',
                            $img->pole_code === $fromPoleCode => 'from_',
                            default                           => 'from_',
                        };
                        return [
                            'id'                 => $img->id,
                            'teardown_report_id' => $img->report_id,
                            'photo_type'         => $base === 'bunching' ? 'bunching' : $prefix . $base,
                            'image_path'         => $img->file_path,
                        ];
                    });

                $report->setRelation('photos', $fallback);
            }

            return $report;
        });
    }

    /**
     * Roll up span_summaries → skycable_nodes actual totals.
     * Call this after any span summary update so the node always
     * reflects the live sum of all its spans' collected quantities.
     */
    private function rollupNodeActuals(int $nodeId): void
    {
        $sums = SkycableSpanSummary::where('node_id', $nodeId)
            ->selectRaw('
                SUM(actual_cable)       as actual_cable,
                SUM(actual_node)        as actual_node,
                SUM(actual_amplifier)   as actual_amplifier,
                SUM(actual_extender)    as actual_extender,
                SUM(actual_tsc)         as actual_tsc,
                SUM(actual_powersupply) as actual_powersupply,
                SUM(actual_ps_housing)  as actual_ps_housing
            ')
            ->first();

        if (! $sums) return;

        $completed = SkycableSpan::where('node_id', $nodeId)
            ->where('status', 'completed')
            ->count();
        $total = SkycableSpan::where('node_id', $nodeId)
            ->whereNotIn('status', ['superseded', 'cancelled'])
            ->count();

        SkycableNode::where('id', $nodeId)->update([
            'actual_cable'       => (float) ($sums->actual_cable       ?? 0),
            'actual_node'        => (int)   ($sums->actual_node        ?? 0),
            'actual_amplifier'   => (int)   ($sums->actual_amplifier   ?? 0),
            'actual_extender'    => (int)   ($sums->actual_extender    ?? 0),
            'actual_tsc'         => (int)   ($sums->actual_tsc         ?? 0),
            'actual_powersupply' => (int)   ($sums->actual_powersupply ?? 0),
            'actual_ps_housing'  => (int)   ($sums->actual_ps_housing  ?? 0),
            'progress_percentage'=> $total > 0 ? round(($completed / $total) * 100, 1) : 0,
        ]);
    }

    private function sanitizePath($name)
    {
        return preg_replace('/[^A-Za-z0-9_\- ]/', '', $name);
    }

    private function completeSpanFromTeardown(?SkycableSpan $span, int $userId, array $data): void
    {
        if (! $span) {
            return;
        }

        $span->loadMissing(['fromPole.pole', 'toPole.pole']);

        $actualCable = $data['actual_cable'] ?? $span->actual_cable ?? 0;

        $span->update([
            'status' => 'completed',
            'completed_at' => now(),
            'actual_cable' => $actualCable,
        ]);

        SkycableSpanSummary::updateOrCreate(
            ['span_id' => $span->id],
            [
                'node_id' => $span->node_id,
                'actual_cable' => $actualCable,
                'actual_node' => $data['nodes_collected'] ?? 0,
                'actual_amplifier' => $data['amplifiers_collected'] ?? 0,
                'actual_extender' => $data['extenders_collected'] ?? 0,
                'actual_tsc' => $data['tsc_collected'] ?? 0,
                'actual_powersupply' => $data['powersupply_collected'] ?? 0,
                'actual_ps_housing' => $data['ps_housing_collected'] ?? 0,
                'updated_by' => $userId,
            ]
        );

        $this->rollupNodeActuals($span->node_id);

        foreach (array_filter([$span->fromPole, $span->toPole]) as $skycablePole) {
            if (! $skycablePole->pole) {
                continue;
            }

            $hasPendingSpan = SkycableSpan::where(function ($query) use ($skycablePole) {
                $query->where('from_pole_id', $skycablePole->id)
                    ->orWhere('to_pole_id', $skycablePole->id);
            })->whereNotIn('status', ['completed', 'superseded', 'cancelled'])->exists();

            if ($hasPendingSpan) {
                $skycablePole->pole->update(['skycable_status' => 'in_progress']);
                continue;
            }

            $skycablePole->pole->update([
                'skycable_status' => 'cleared',
                'skycable_cleared_at' => now(),
            ]);
            $skycablePole->update(['cleared_at' => now()]);
        }
    }

    public function storeDirect(Request $request)
    {
        // Deduplicate by local_id — return existing record if already submitted
        if ($localId = $request->input('local_id')) {
            $existing = SkycableTeardownReport::where('local_id', $localId)->first();
            if ($existing) {
                return response()->json($existing->fresh()->load(['span.fromPole.pole', 'span.toPole.pole', 'team', 'lineman']), 200);
            }
        }

        $request->validate([
            'pole_span_id'              => 'required|exists:skycable_spans,id',
            'local_id'                  => 'nullable|string|max:64',
            'started_at'                => 'nullable|date',
            'finished_at'               => 'nullable|date',
            'captured_at_device'        => 'nullable|date',
            'did_collect_all_cable'     => 'nullable|string',
            'collected_cable'           => 'nullable|numeric|min:0',
            'recovered_cable'           => 'nullable|numeric|min:0',
            'unrecovered_cable'         => 'nullable|numeric|min:0',
            'unrecovered_reason'        => 'nullable|string',
            'expected_cable'            => 'nullable|numeric|min:0',
            'nodes_collected'           => 'nullable|integer|min:0',
            'amplifiers_collected'      => 'nullable|integer|min:0',
            'extenders_collected'       => 'nullable|integer|min:0',
            'tsc_collected'             => 'nullable|integer|min:0',
            'powersupply_collected'     => 'nullable|integer|min:0',
            'ps_housing_collected'      => 'nullable|integer|min:0',
            'collected_node'            => 'nullable|integer|min:0',
            'collected_amplifier'       => 'nullable|integer|min:0',
            'collected_extender'        => 'nullable|integer|min:0',
            'collected_tsc'             => 'nullable|integer|min:0',
            'collected_powersupply'     => 'nullable|integer|min:0',
            'collected_powersupply_housing' => 'nullable|integer|min:0',
            'gps_latitude'              => 'nullable|numeric',
            'gps_longitude'             => 'nullable|numeric',
            'from_pole_latitude'        => 'nullable|numeric',
            'from_pole_longitude'       => 'nullable|numeric',
            'to_pole_latitude'          => 'nullable|numeric',
            'to_pole_longitude'         => 'nullable|numeric',
            'from_before'  => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'from_after'   => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'from_pole_tag' => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'to_before'    => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'to_after'     => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'to_pole_tag'   => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'bunching'      => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
        ]);

        $user = $request->user();

        // Build structured photo paths for storeDirect
        $directSpan     = SkycableSpan::with(['node.area', 'fromPole.pole', 'toPole.pole'])->find($request->input('pole_span_id'));
        $dAreaName   = $this->sanitizePath($directSpan?->node?->area?->name ?? 'Unknown_Area');
        $dNodeName   = $this->sanitizePath($directSpan?->node?->name ?? 'Unknown_Node');

        // A lineman can physically walk a span starting from either end, so the
        // mobile app's "from"/"to" pole_id doesn't always match the span's stored
        // from_pole/to_pole. If the submitted from_pole_id is actually the span's
        // to_pole, the submission is reversed — swap so GPS/photos/codes land on
        // the correct physical pole instead of the one on "the other side".
        $requestFromPoleId = (int) $request->input('from_pole_id');
        $isReversed = $requestFromPoleId
            && $directSpan?->toPole?->pole_id === $requestFromPoleId
            && $directSpan?->fromPole?->pole_id !== $requestFromPoleId;

        $dFromPole   = $isReversed ? $directSpan?->toPole?->pole   : $directSpan?->fromPole?->pole;
        $dToPole     = $isReversed ? $directSpan?->fromPole?->pole : $directSpan?->toPole?->pole;
        $dSpanId     = $directSpan?->id ?? 'unknown';
        $requestedFromPoleCode = trim((string) $request->input('from_pole_code', $dFromPole?->pole_code ?? 'unknown'));
        $requestedToPoleCode   = trim((string) $request->input('to_pole_code', $dToPole?->pole_code ?? 'unknown'));

        $report = DB::transaction(function () use ($request, $user, $dAreaName, $dNodeName, $dFromPole, $dToPole, $dSpanId, $directSpan, $requestedFromPoleCode, $requestedToPoleCode) {
            $report = SkycableTeardownReport::create([
                'local_id'           => $request->input('local_id') ?: null,
                'span_id'            => $request->input('pole_span_id'),
                'team_id'            => $user->team_id ?? null,
                'lineman_id'         => $user->id,
                'start_time'         => $request->input('started_at')    ?? now(),
                'end_time'           => $request->input('finished_at')   ?? now(),
                'status'             => 'submitted',
                'actual_cable'       => $request->input('collected_cable') ?? $request->input('recovered_cable'),
                'expected_cable'     => $request->input('expected_cable'),
                'nodes_collected'    => $request->input('nodes_collected') ?? $request->input('collected_node') ?? 0,
                'amplifiers_collected' => $request->input('amplifiers_collected') ?? $request->input('collected_amplifier') ?? 0,
                'extenders_collected' => $request->input('extenders_collected') ?? $request->input('collected_extender') ?? 0,
                'tsc_collected'      => $request->input('tsc_collected') ?? $request->input('collected_tsc') ?? 0,
                'powersupply_collected' => $request->input('powersupply_collected') ?? $request->input('collected_powersupply') ?? 0,
                'ps_housing_collected'  => $request->input('ps_housing_collected') ?? $request->input('collected_powersupply_housing') ?? 0,
                'offline_mode'       => false,
                'captured_at_device' => $request->input('captured_at_device'),
                'captured_lat'       => $request->input('from_pole_latitude') ?? $request->input('gps_latitude'),
                'captured_lng'       => $request->input('from_pole_longitude') ?? $request->input('gps_longitude'),
                'received_at_server' => now(),
            ]);

            $photoMap = [
                'from_before'   => 'from_before',
                'from_after'    => 'from_after',
                'from_pole_tag' => 'from_pole_tag',
                'to_before'     => 'to_before',
                'to_after'      => 'to_after',
                'to_pole_tag'   => 'to_pole_tag',
                'bunching'      => 'bunching',
            ];

            foreach ($photoMap as $field => $photoType) {
                if (!$request->hasFile($field)) continue;

                $isBunching = $field === 'bunching';
                $isToPole   = str_starts_with($field, 'to_');
                $activePole = $isToPole ? $dToPole : $dFromPole;
                $rawPoleCode = $isToPole ? $requestedToPoleCode : $requestedFromPoleCode;
                $poleCode   = $this->sanitizePath($rawPoleCode !== '' ? $rawPoleCode : ($activePole?->pole_code ?? 'unknown'));
                $poleId     = $activePole?->id ?? 0;

                if ($isBunching) {
                    $typeSlug = 'bunching';
                    $filePath = "spans/{$dSpanId}/{$dSpanId}_bunching.jpg";
                } elseif (str_contains($field, 'pole_tag')) {
                    $typeSlug = 'poletag';
                    $filePath = "{$dAreaName}/{$dNodeName}/{$poleCode}/{$poleCode}_poletag.jpg";
                } elseif (str_contains($field, 'after')) {
                    $typeSlug = 'after';
                    $filePath = "{$dAreaName}/{$dNodeName}/{$poleCode}/{$poleCode}_after.jpg";
                } else {
                    $typeSlug = 'before';
                    $filePath = "{$dAreaName}/{$dNodeName}/{$poleCode}/{$poleCode}_before.jpg";
                }

                $path = $this->storePhoto($request->file($field), 'teardown', 1280, $filePath);

                SkycableTeardownPhoto::create([
                    'teardown_report_id' => $report->id,
                    'photo_type'         => $photoType,
                    'image_path'         => $path,
                ]);

                PoleTeardownImage::create([
                    'report_id'      => $report->id,
                    'pole_id'        => $poleId,
                    'area_id'        => $directSpan?->node?->area_id,
                    'node_id'        => $directSpan?->node_id,
                    'pole_code'      => $rawPoleCode !== '' ? $rawPoleCode : ($activePole?->pole_code ?? 'unknown'),
                    'image_type'     => $typeSlug,
                    'file_path'      => $path,
                    'inventory_type' => 'skycable',
                ]);
            }

            AuditLog::record('create', $report, null, $report->toArray());

            // Bust dashboard cache so the live feed shows the new submission immediately
            Cache::forget('teardowns_index_15_p1');
            Cache::forget('teardowns_index_50_p1');

            // Write captured GPS back to the physical pole records so they appear on the map.
            // Uses $dFromPole/$dToPole (already corrected for a reversed submission above)
            // instead of $directSpan->fromPole/toPole directly.
            if ($request->from_pole_latitude && $dFromPole) {
                $dFromPole->update([
                    'lat' => $request->from_pole_latitude,
                    'lng' => $request->from_pole_longitude,
                ]);
            }
            if ($request->to_pole_latitude && $dToPole) {
                $dToPole->update([
                    'lat' => $request->to_pole_latitude,
                    'lng' => $request->to_pole_longitude,
                ]);
            }

            // Write collected cable & expected cable back to the span for fast node-level aggregation
            $directSpan->update([
                'status'        => 'completed',
                'completed_at'  => now(),
                'actual_cable'  => $request->input('collected_cable') ?? $request->input('recovered_cable') ?? 0,
                'length_meters' => $request->input('expected_cable') ?? $directSpan->length_meters ?? 0,
            ]);

            // Upsert into span_summaries — 1 row per span, all quantities in one place.
            // Carry the expected_* columns through explicitly (preferring whatever the
            // mobile app submitted, falling back to the existing row) — otherwise this
            // upsert silently drops them and the redline/vicinity reports lose the
            // collectable-components badge for this span the moment it's torn down.
            $existingSummary = $directSpan->summary;
            SkycableSpanSummary::updateOrCreate(
                ['span_id' => $directSpan->id],
                [
                    'node_id'              => $directSpan->node_id,
                    'expected_cable'       => $request->input('expected_cable') ?? $existingSummary?->expected_cable ?? $directSpan->length_meters ?? 0,
                    'expected_node'        => $request->input('expected_node') ?? $existingSummary?->expected_node ?? 0,
                    'expected_amplifier'   => $request->input('expected_amplifier') ?? $existingSummary?->expected_amplifier ?? 0,
                    'expected_extender'    => $request->input('expected_extender') ?? $existingSummary?->expected_extender ?? 0,
                    'expected_tsc'         => $request->input('expected_tsc') ?? $existingSummary?->expected_tsc ?? 0,
                    'expected_powersupply' => $request->input('expected_powersupply') ?? $existingSummary?->expected_powersupply ?? 0,
                    'expected_ps_housing'  => $request->input('expected_powersupply_housing') ?? $existingSummary?->expected_ps_housing ?? 0,
                    'actual_cable'         => $request->input('collected_cable') ?? $request->input('recovered_cable') ?? 0,
                    'actual_node'          => $request->input('nodes_collected') ?? $request->input('collected_node') ?? 0,
                    'actual_amplifier'     => $request->input('amplifiers_collected') ?? $request->input('collected_amplifier') ?? 0,
                    'actual_extender'      => $request->input('extenders_collected') ?? $request->input('collected_extender') ?? 0,
                    'actual_tsc'           => $request->input('tsc_collected') ?? $request->input('collected_tsc') ?? 0,
                    'actual_powersupply'   => $request->input('powersupply_collected') ?? $request->input('collected_powersupply') ?? 0,
                    'actual_ps_housing'    => $request->input('ps_housing_collected') ?? $request->input('collected_powersupply_housing') ?? 0,
                    'updated_by'           => $user->id,
                ]
            );

            // Roll up span-level actuals → node-level actuals so dashboard totals stay current
            $this->rollupNodeActuals($directSpan->node_id);

            // For each pole in the span, check if ALL connected spans are now completed.
            // If so, mark the physical pole as cleared.
            $skyPoles = array_filter([$directSpan->fromPole, $directSpan->toPole]);
            foreach ($skyPoles as $sp) {
                if (! $sp->pole) continue;

                $hasPendingSpan = SkycableSpan::where(function ($q) use ($sp) {
                    $q->where('from_pole_id', $sp->id)
                      ->orWhere('to_pole_id',   $sp->id);
                })->where('status', '!=', 'completed')->exists();

                if (! $hasPendingSpan) {
                    // All spans done → fully cleared
                    $sp->pole->update([
                        'skycable_status'     => 'cleared',
                        'skycable_cleared_at' => now(),
                    ]);
                    // Also record cleared_at on the skycable_poles pivot row
                    $sp->update(['cleared_at' => now()]);
                } else {
                    // At least one span done but others still pending → in_progress
                    $sp->pole->update(['skycable_status' => 'in_progress']);
                }
            }

            return $report;
        });

        $this->bumpSkycableCacheVersion();

        return response()->json($report->fresh()->load(['span.fromPole.pole', 'span.toPole.pole', 'span.summary', 'team', 'lineman', 'slots', 'photos']), 201);
    }

    public function review(Request $request, SkycableTeardownReport $report)
    {
        $data = $request->validate([
            'action'           => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|nullable|string',
        ]);

        $user = $request->user();
        $old  = $report->toArray();

        if ($data['action'] === 'approve') {
            $report->update([
                'status'               => 'subcon_approved',
                'subcon_reviewed_by'   => $user->id,
                'subcon_reviewed_at'   => now(),
                'rejection_reason'     => null,
            ]);
        } else {
            $report->update([
                'status'             => 'rejected',
                'subcon_reviewed_by' => $user->id,
                'subcon_reviewed_at' => now(),
                'rejection_reason'   => $data['rejection_reason'],
            ]);
        }

        AuditLog::record('update', $report, $old, $report->toArray());
        $this->bumpSkycableCacheVersion();

        return response()->json($report->fresh());
    }

    public function backendApprove(Request $request, SkycableTeardownReport $report)
    {
        $data = $request->validate([
            'action'           => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|nullable|string',
        ]);

        $user = $request->user();
        $old  = $report->toArray();

        if ($data['action'] === 'approve') {
            $report->update([
                'status'               => 'backend_approved',
                'backend_approved_by'  => $user->id,
                'backend_approved_at'  => now(),
                'rejection_reason'     => null,
            ]);

            // Sync hardware counts to the Span Summary (flattened)
            $span = $report->span()->with('summary')->first();
            if ($span && $span->summary) {
                $reports = SkycableTeardownReport::where('span_id', $span->id)
                    ->where('status', 'backend_approved')
                    ->get();
                
                $span->summary->update([
                    'actual_node'          => $reports->sum('nodes_collected'),
                    'actual_amplifier'     => $reports->sum('amplifiers_collected'),
                    'actual_extender'      => $reports->sum('extenders_collected'),
                    'actual_tsc'           => $reports->sum('tsc_collected'),
                    'actual_powersupply'   => $reports->sum('powersupply_collected'),
                    'actual_ps_housing'    => $reports->sum('ps_housing_collected'),
                    'actual_cable'         => $reports->sum('actual_cable'),
                    'updated_by'           => $user->id,
                ]);

                // Roll up to node-level actuals
                $this->rollupNodeActuals($span->node_id);
            }

            // Release the cable slots — set occupied_by to 'free' (not null, column is an enum)
            foreach ($report->slots as $slot) {
                PoleCableSlot::where('id', $slot->pole_cable_slot_id)
                    ->update(['status' => 'free', 'occupied_by' => 'free']);
            }

            // Mark span as completed if ALL its teardown reports are now backend_approved
            $span = $report->span()->with(['fromPole.pole', 'toPole.pole'])->first();
            $spanJustCompleted = $span->teardownReports()
                ->where('status', '!=', 'backend_approved')
                ->doesntExist();
            if ($spanJustCompleted) {
                $span->update(['status' => 'completed', 'completed_at' => now()]);
            }

            // Auto-clear a pole only when ALL spans that involve it are completed.
            // A pole shared between span 1→2 (done) and 1→3 (pending) stays pending.
            $skyPoles = array_filter([$span->fromPole, $span->toPole]);
            foreach ($skyPoles as $sp) {
                $hasPendingSpan = SkycableSpan::where(function ($q) use ($sp) {
                    $q->where('from_pole_id', $sp->id)
                      ->orWhere('to_pole_id',   $sp->id);
                })->where('status', '!=', 'completed')->exists();

                if (! $hasPendingSpan && $sp->pole) {
                    $sp->pole->update([
                        'skycable_status'     => 'cleared',
                        'skycable_cleared_at' => now(),
                    ]);
                    $sp->update(['cleared_at' => now()]);
                }
            }
        } else {
            $report->update([
                'status'              => 'rejected',
                'backend_approved_by' => $user->id,
                'backend_approved_at' => now(),
                'rejection_reason'    => $data['rejection_reason'],
            ]);
        }

        AuditLog::record('update', $report, $old, $report->toArray());
        $this->bumpSkycableCacheVersion();

        return response()->json($report->fresh());
    }
}
