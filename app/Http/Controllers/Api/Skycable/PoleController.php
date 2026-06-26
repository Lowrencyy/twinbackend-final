<?php

namespace App\Http\Controllers\Api\Skycable;

use App\Http\Concerns\CachesSkycableResponses;
use App\Http\Concerns\StoresPhotos;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Pole;
use App\Models\PoleCableSlot;
use App\Models\PoleReport;
use App\Models\PoleTeardownImage;
use App\Models\SkycableNode;
use App\Models\SkycablePole;
use App\Models\SkycableSpan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PoleController extends Controller
{
    use CachesSkycableResponses;
    use StoresPhotos;
    public function index(SkycableNode $node)
    {
        return $this->skycableCachedJson("poles.byNode.{$node->id}", 120, function () use ($node) {
            return $node->skycablePoles()
                ->with(['pole.barangay', 'pole.cableSlots'])
                ->orderBy('sequence')
                ->get();
        });
    }

    /**
     * PUT skycable/nodes/{node}/poles/{skycablePole}
     * Mobile app records date_start (when lineman begins a pole)
     * and cleared_at (when teardown is submitted for that pole).
     */
    public function updatePole(Request $request, SkycableNode $node, SkycablePole $skycablePole)
    {
        $data = $request->validate([
            'date_start' => 'sometimes|nullable|date',
            'cleared_at' => 'sometimes|nullable|date',   // finished_at from mobile
            'status' => 'sometimes|in:pending,in_progress,completed',
        ]);

        $update = [];

        // date_start: only set once (first time)
        if (! empty($data['date_start']) && ! $skycablePole->date_start) {
            $update['date_start'] = $data['date_start'];
        }

        // cleared_at (finished_at): always update when provided
        if (! empty($data['cleared_at'])) {
            $update['cleared_at'] = $data['cleared_at'];
        }

        // explicit status override (model's saving() hook will also auto-set it)
        if (! empty($data['status'])) {
            $update['status'] = $data['status'];
        }

        if (! empty($update)) {
            $skycablePole->update($update);
            $this->bumpSkycableCacheVersion();
        }

        return response()->json($skycablePole->fresh()->load('pole'));
    }

    /**
     * POST skycable/poles/{pole}/report
     * Submitted after a field staff captures GPS + photos for a pole_report node.
     */
    public function storeReport(Request $request, Pole $pole)
    {
        $data = $request->validate([
            'condition'       => 'nullable|string|max:100',
            'material'        => 'nullable|string|max:100',
            'height_ft'       => 'nullable|string|max:20',
            'landmark'        => 'nullable|string|max:500',
            'notes'           => 'nullable|string|max:1000',
            'node_id'         => 'nullable|exists:skycable_nodes,id',
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
            'gps_captured_at' => 'nullable|string',
            'slots'           => 'nullable|string',
            'before_photo'    => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'after_photo'     => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
            'tag_photo'       => 'nullable|file|mimes:jpg,jpeg,png|max:15360',
        ]);

        // Also mark the node-scoped skycable_poles pivot row as cleared.
        $skycablePoleQuery = SkycablePole::where('pole_id', $pole->id);
        if (! empty($data['node_id'])) {
            $skycablePoleQuery->where('node_id', $data['node_id']);
        }

        if (empty($data['node_id']) && $skycablePoleQuery->count() > 1) {
            return response()->json([
                'message' => 'This pole exists in multiple nodes. Provide node_id so the report is attached to the correct node.',
            ], 422);
        }

        $skycablePole = $skycablePoleQuery->first();
        if (! empty($data['node_id']) && ! $skycablePole) {
            return response()->json([
                'message' => 'Pole not found in the provided node.',
            ], 404);
        }

        // Update pole GPS and mark as cleared after the node scope is known.
        $poleUpdate = ['skycable_status' => 'cleared', 'skycable_cleared_at' => Carbon::now()];
        if (($data['latitude'] ?? null) !== null && ($data['longitude'] ?? null) !== null) {
            $poleUpdate['lat'] = $data['latitude'];
            $poleUpdate['lng'] = $data['longitude'];
        }
        $pole->update($poleUpdate);
        Cache::forget('skycable_all_poles');

        if ($skycablePole) {
            $skycablePole->update(['cleared_at' => Carbon::now()]);
        }

        $report = PoleReport::create([
            'pole_id'        => $pole->id,
            'node_id'        => $skycablePole?->node_id,
            'submitted_by'   => $request->user()->id,
            'condition'      => $data['condition'] ?? null,
            'material'       => $data['material'] ?? null,
            'height_ft'      => $data['height_ft'] ?? null,
            'landmark'       => $data['landmark'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'latitude'       => $data['latitude'] ?? null,
            'longitude'      => $data['longitude'] ?? null,
            'gps_captured_at'=> $data['gps_captured_at'] ?? null,
            'slots'          => isset($data['slots']) ? json_decode($data['slots'], true) : null,
        ]);

        // Save photos that were sent inline with the report submission
        $node      = $skycablePole ? $skycablePole->node : ($report->node_id ? SkycableNode::find($report->node_id) : null);
        $area      = $node?->area;
        $areaName  = $this->sanitizePath($area?->name ?? 'Unknown_Area');
        $nodeName  = $this->sanitizePath($node?->name ?? 'Unknown_Node');
        $poleCode  = $this->sanitizePath($pole->pole_code);

        $photoFields = [
            'before_photo' => 'before',
            'after_photo'  => 'after',
            'tag_photo'    => 'pole_tag',
        ];

        foreach ($photoFields as $field => $imageType) {
            if ($request->hasFile($field) && $request->file($field)->isValid()) {
                try {
                    $typeSlug   = $imageType === 'pole_tag' ? 'poletag' : $imageType;
                    $folderPath = "{$areaName}/{$nodeName}/{$poleCode}";
                    $fileName   = "{$pole->id}_{$typeSlug}.jpg";
                    $fullPath   = "{$folderPath}/{$fileName}";

                    $storedPath = $this->storePhoto($request->file($field), 'teardown', 1280, $fullPath);

                    PoleTeardownImage::create([
                        'report_id'      => $report->id,
                        'pole_id'        => $pole->id,
                        'area_id'        => $area?->id,
                        'node_id'        => $node?->id,
                        'pole_code'      => $pole->pole_code,
                        'image_type'     => $imageType,
                        'file_path'      => $storedPath,
                        'inventory_type' => 'skycable',
                    ]);
                } catch (\Throwable $e) {
                    // Photo save failure should not fail the whole report
                    \Log::warning("PoleReport photo save failed [{$field}]: " . $e->getMessage());
                }
            }
        }

        $this->bumpSkycableCacheVersion();

        return response()->json($report->load(['pole', 'node', 'submitter', 'photos']), 201);
    }

    public function showReport(Request $request, PoleReport $poleReport)
    {
        return $this->skycableCachedJson("poleReports.show.{$poleReport->id}", 120, function () use ($poleReport) {
            $poleReport->load(['pole', 'node', 'submitter.team']);

            // Filter photos by pole_id as well to avoid report_id collisions with
            // PoleTeardownImage records from other sources (e.g. span teardown uploads)
            $photos = PoleTeardownImage::where('report_id', $poleReport->id)
                ->where('pole_id', $poleReport->pole_id)
                ->get();
            $poleReport->setRelation('photos', $photos);

            return $poleReport;
        });
    }

    private function sanitizePath(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\- ]/', '', $name);
    }

    /**
     * GET skycable/pole-reports
     * Returns paginated pole reports for the web / mobile logs view.
     */
    public function listReports(Request $request)
    {
        $perPage = min((int) ($request->per_page ?? 50), 200);

        return $this->skycableCachedJson('poleReports.index', 60, function () use ($request, $perPage) {
            return PoleReport::with(['pole', 'node', 'submitter.team', 'photos'])
                ->when($request->node_id, fn($q) => $q->where('node_id', (int) $request->node_id))
                ->latest()
                ->paginate($perPage);
        }, $request);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pole_code' => 'required|string|max:100',
            'barangay_code' => 'nullable|string|max:20',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'node_id' => 'required|exists:skycable_nodes,id',
            'sequence' => 'nullable|integer|min:1',
        ]);

        $pole = Pole::create([
            'pole_code' => $data['pole_code'],
            'barangay_code' => $data['barangay_code'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            // This endpoint is only ever hit by the mobile "Add Pole" flow (AsBuilt
            // import uses a separate path), so every pole created here is by
            // definition one the lineman added in the field.
            'is_manual' => true,
        ]);

        $maxSeq = SkycablePole::where('node_id', $data['node_id'])->max('sequence') ?? 0;
        $skycablePole = SkycablePole::create([
            'node_id' => $data['node_id'],
            'pole_id' => $pole->id,
            'sequence' => $data['sequence'] ?? ($maxSeq + 1),
        ]);

        AuditLog::record('create', $pole, null, $pole->toArray());
        Cache::forget('skycable_all_poles');
        $this->bumpSkycableCacheVersion();

        return response()->json(['pole' => $pole, 'node_pole' => $skycablePole], 201);
    }

    public function mapPins()
    {
        return $this->skycableCachedJson('poles.mapPins', 60, function () {
            // Eager-load teardown report GPS as fallback for poles with no lat/lng
            $pins = SkycablePole::with([
            'pole.barangay',
            'node.area',
            'spansFrom.teardownReports',
            'spansTo.teardownReports',
        ])
            ->get()
            ->filter(fn ($sp) => $sp->pole)
            ->map(function ($sp) {
                // Primary: pole's own GPS
                $lat = $sp->pole->lat ? (float) $sp->pole->lat : null;
                $lng = $sp->pole->lng ? (float) $sp->pole->lng : null;

                // Fallback: latest teardown report for any connected span that has GPS
                if (! $lat || ! $lng) {
                    $report = $sp->spansFrom->merge($sp->spansTo)
                        ->flatMap(fn ($span) => $span->teardownReports)
                        ->filter(fn ($r) => $r->captured_lat && $r->captured_lng)
                        ->sortByDesc('id')
                        ->first();

                    if ($report) {
                        $lat = (float) $report->captured_lat;
                        $lng = (float) $report->captured_lng;

                        $isSharedWithAnotherNode = SkycablePole::where('pole_id', $sp->pole->id)
                            ->where('id', '!=', $sp->id)
                            ->exists();

                        // Persist only when this master pole is not shared by another node.
                        if (! $isSharedWithAnotherNode) {
                            $sp->pole->update(['lat' => $lat, 'lng' => $lng]);
                        }
                    }
                }

                if (! $lat || ! $lng) {
                    return null;
                }

                return [
                    'id' => $sp->id,
                    'skycable_pole_id' => $sp->id,
                    'pole_id' => $sp->pole->id,
                    'pole_code' => $sp->pole->pole_code,
                    'lat' => $lat,
                    'lng' => $lng,
                    'skycable_status' => $sp->pole->skycable_status ?? 'pending',
                    'barangay' => optional($sp->pole->barangay)->name,
                    'node' => optional($sp->node)->name,
                    'area' => optional(optional($sp->node)->area)->name,
                ];
            })
            ->filter()
            ->values();

            return $pins;
        });
    }

    public function allPoles()
    {
        return $this->skycableCachedJson('poles.all', 120, function () {
            return SkycablePole::with(['pole.barangay.city.province', 'node.area'])
                ->get()
                ->filter(fn ($sp) => $sp->pole)
                ->map(fn ($sp) => [
                    'id' => $sp->id,
                    'skycable_pole_id' => $sp->id,
                    'pole_id' => $sp->pole->id,
                    'pole_code' => $sp->pole->pole_code,
                    'lat' => $sp->pole->lat ? (float) $sp->pole->lat : null,
                    'lng' => $sp->pole->lng ? (float) $sp->pole->lng : null,
                    'has_gps' => (bool) ($sp->pole->lat && $sp->pole->lng),
                    'skycable_status' => $sp->pole->skycable_status ?? 'pending',
                    'barangay' => optional($sp->pole->barangay)->name,
                    'city' => optional(optional($sp->pole->barangay)->city)->name,
                    'province' => optional(optional(optional($sp->pole->barangay)->city)->province)->name,
                    'node' => optional($sp->node)->name,
                    'node_id' => $sp->node_id,
                    'area' => optional(optional($sp->node)->area)->name,
                ])
                ->values();
        });
    }

    public function showByCode(string $code)
    {
        $pole = Pole::where('pole_code', $code)->firstOrFail();

        return $this->show($pole);
    }

    public function show(Pole $pole)
    {
        return $this->skycableCachedJson("poles.show.{$pole->id}", 120, function () use ($pole) {
            $pole->load(['barangay.city.province', 'cableSlots']);

            // Collect teardown photos for this pole (from all spans it appears in)
            $skycablePoles = SkycablePole::where('pole_id', $pole->id)->get();

            $photos = ['before' => null, 'after' => null, 'pole_tag' => null];

            foreach ($skycablePoles as $sp) {
                $spans = SkycableSpan::where('from_pole_id', $sp->id)
                    ->orWhere('to_pole_id', $sp->id)
                    ->with(['teardownReports.photos'])
                    ->get();

                foreach ($spans as $span) {
                    $isFrom = $span->from_pole_id === $sp->id;

                    foreach ($span->teardownReports as $report) {
                        foreach ($report->photos as $photo) {
                            $type = $photo->photo_type;
                            if ($isFrom) {
                                if ($type === 'from_before' && ! $photos['before']) {
                                    $photos['before'] = $photo->image_path;
                                }
                                if ($type === 'from_after' && ! $photos['after']) {
                                    $photos['after'] = $photo->image_path;
                                }
                                if ($type === 'from_pole_tag' && ! $photos['pole_tag']) {
                                    $photos['pole_tag'] = $photo->image_path;
                                }
                            } else {
                                if ($type === 'to_before' && ! $photos['before']) {
                                    $photos['before'] = $photo->image_path;
                                }
                                if ($type === 'to_after' && ! $photos['after']) {
                                    $photos['after'] = $photo->image_path;
                                }
                                if ($type === 'to_pole_tag' && ! $photos['pole_tag']) {
                                    $photos['pole_tag'] = $photo->image_path;
                                }
                            }
                        }
                    }
                }
            }

            $pole->photos = $photos;

            return $pole;
        });
    }

    public function update(Request $request, Pole $pole)
    {
        $data = $request->validate([
            'pole_code' => 'sometimes|string|max:100',
            'lat' => 'sometimes|nullable|numeric',
            'lng' => 'sometimes|nullable|numeric',
            'skycable_status' => 'sometimes|in:pending,in_progress,cleared',
        ]);

        $old = $pole->toArray();
        $pole->update($data);
        AuditLog::record('update', $pole, $old, $pole->toArray());
        Cache::forget('skycable_all_poles');
        $this->bumpSkycableCacheVersion();

        return response()->json($pole);
    }

    public function slots(Pole $pole)
    {
        return $this->skycableCachedJson("poles.slots.{$pole->id}", 300, function () use ($pole) {
            return $pole->cableSlots;
        });
    }

    public function destroy(Pole $pole)
    {
        AuditLog::record('delete', $pole, $pole->toArray(), null);
        SkycablePole::where('pole_id', $pole->id)->delete();
        $pole->delete();
        Cache::forget('skycable_all_poles');
        $this->bumpSkycableCacheVersion();

        return response()->json(['message' => 'Pole deleted.']);
    }

    public function addSlot(Request $request, Pole $pole)
    {
        $data = $request->validate([
            'slot_label' => 'required|string|max:50',
            'occupied_by' => 'nullable|in:skycable,globe,meralco,others,free',
        ]);

        $occupiedBy = $data['occupied_by'] ?? 'free';
        $status = ($occupiedBy !== 'free') ? 'occupied' : 'free';

        $old = null;
        $slot = PoleCableSlot::where('pole_id', $pole->id)
            ->where('slot_label', $data['slot_label'])
            ->first();

        if ($slot) {
            $old = $slot->toArray();
            $slot->update(['occupied_by' => $occupiedBy, 'status' => $status]);
        } else {
            $slot = PoleCableSlot::create([
                'pole_id' => $pole->id,
                'slot_label' => $data['slot_label'],
                'occupied_by' => $occupiedBy,
                'status' => $status,
            ]);
        }

        AuditLog::record($old ? 'update' : 'create', $slot, $old, $slot->toArray());
        $this->bumpSkycableCacheVersion();

        return response()->json($slot, $old ? 200 : 201);
    }

    public function syncPole(Request $request, SkycableNode $node)
    {
        $data = $request->validate([
            'pole_id' => 'required_without:skycable_pole_id|exists:poles,id',
            'skycable_pole_id' => 'required_without:pole_id|exists:skycable_poles,id',
            'date_start' => 'sometimes|nullable|date',
            'cleared_at' => 'sometimes|nullable|date',  // finished_at from mobile
            'status' => 'sometimes|in:pending,in_progress,completed',
        ]);

        $skycablePole = SkycablePole::where('node_id', $node->id)
            ->when(
                ! empty($data['skycable_pole_id']),
                fn ($q) => $q->where('id', $data['skycable_pole_id']),
                fn ($q) => $q->where('pole_id', $data['pole_id'])
            )
            ->first();

        if (! $skycablePole) {
            return response()->json(['message' => 'Pole not found in this node.'], 404);
        }

        $update = [];

        if (! empty($data['date_start']) && ! $skycablePole->date_start) {
            $update['date_start'] = $data['date_start'];

            // First time this pole is started — assign the next teardown sequence
            // server-side (authoritative, ignores whatever the client computed
            // locally) so the redline/vicinity reports show the real order poles
            // were started in this node, always beginning at 1.
            $nextSeq = SkycablePole::where('node_id', $node->id)
                ->whereNotNull('date_start')
                ->max('sequence') ?? 0;
            $update['sequence'] = $nextSeq + 1;
        }

        if (! empty($data['cleared_at'])) {
            $update['cleared_at'] = $data['cleared_at'];
        }

        if (! empty($data['status'])) {
            $update['status'] = $data['status'];
        }

        if (! empty($update)) {
            $skycablePole->update($update);  // saving() hook auto-computes duration + status
            $this->bumpSkycableCacheVersion();
        }

        return response()->json($skycablePole->fresh()->load('pole'));
    }

    public function updateGps(Request $request, Pole $pole)
    {
        $data = $request->validate([
            'lat' => 'sometimes|nullable|numeric',
            'lng' => 'sometimes|nullable|numeric',
            'map_latitude' => 'sometimes|nullable|numeric',
            'map_longitude' => 'sometimes|nullable|numeric',
        ]);

        $lat = $data['lat'] ?? $data['map_latitude'] ?? null;
        $lng = $data['lng'] ?? $data['map_longitude'] ?? null;

        if ($lat === null && $lng === null) {
            return response()->json(['message' => 'No GPS coordinates provided.'], 422);
        }

        $old = $pole->only(['lat', 'lng']);
        $pole->update(array_filter(['lat' => $lat, 'lng' => $lng], fn ($v) => $v !== null));
        AuditLog::record('update', $pole, $old, $pole->fresh()->only(['lat', 'lng']));
        Cache::forget('skycable_all_poles');
        $this->bumpSkycableCacheVersion();

        return response()->json(['message' => 'GPS updated.', 'pole' => $pole->fresh()]);
    }
}
