<?php

namespace App\Http\Controllers\Api\Skycable;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Pole;
use App\Models\SkycableArea;
use App\Models\SkycableNode;
use App\Models\SkycablePole;
use App\Models\SkycableSpan;
use App\Models\SkycableSpanSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AsBuiltController extends Controller
{
    /**
     * POST /asbuilt/import
     *
     * node_id  = the VARCHAR identifier string (e.g. "TY1401") stored in skycable_nodes.node_id
     * node_name = human name like "MONTEVISTA SUBD." — saved to skycable_nodes.name
     * region / province / city = passed in payload, saved to node
     * poles[].barangay_name = optional per pole; node.barangay_name = majority value
     * source_file is set to "asbuilt" automatically
     */
    public function import(Request $request)
    {
        // ── Accept either a JSON file upload OR a raw JSON body ───────────────
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            if (! in_array($file->getMimeType(), ['application/json', 'text/plain', 'text/json'])) {
                return response()->json(['message' => 'Uploaded file must be a .json file.'], 422);
            }

            $decoded = json_decode(file_get_contents($file->getRealPath()), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['message' => 'Invalid JSON file: '.json_last_error_msg()], 422);
            }

            $request->merge($decoded);
        }

        $request->validate([
            'node_id'                      => 'required|string|max:100',
            'node_name'                    => 'required|string|max:255',
            'area_id'                      => 'required|exists:skycable_areas,id',
            'region'                       => 'nullable|string|max:255',
            'province'                     => 'nullable|string|max:255',
            'city'                         => 'nullable|string|max:255',
            'barangay_name'                => 'nullable|string|max:255',
            'subcontractor_id'             => 'nullable|integer|exists:subcontractors,id',
            'team_id'                      => 'nullable|integer|exists:teams,id',
            'poles'                        => 'required|array|min:1',
            'poles.*.pole_index'           => 'required|integer|min:1',
            'poles.*.pole_code'            => 'required|string|max:100',
            'poles.*.latitude'             => 'required|numeric|between:-90,90',
            'poles.*.longitude'            => 'required|numeric|between:-180,180',
            'spans'                        => 'nullable|array',
            'spans.*.from_pole_code'       => 'required|string',
            'spans.*.to_pole_code'         => 'required|string',
            'spans.*.from_pole_index'      => 'required_with:spans|integer|min:1',
            'spans.*.to_pole_index'        => 'required_with:spans|integer|min:1',
            'spans.*.strand_length'        => 'required_with:spans|numeric|min:0',
            'spans.*.number_of_runs'       => 'required_with:spans|integer|min:1',
            'spans.*.components'           => 'nullable|array',
            'spans.*.components.node'      => 'nullable|integer|min:0',
            'spans.*.components.amplifier' => 'nullable|integer|min:0',
            'spans.*.components.extender'  => 'nullable|integer|min:0',
            'spans.*.components.tsc'       => 'nullable|integer|min:0',
            'spans.*.components.powersupply' => 'nullable|integer|min:0',
            'spans.*.components.ps_housing'  => 'nullable|integer|min:0',
        ]);

        // ── Reject duplicate node_id within the same area (case-insensitive) ──
        $nodeId = trim($request->node_id);
        if (SkycableNode::where('area_id', $request->area_id)
                ->whereRaw('UPPER(node_id) = ?', [strtoupper($nodeId)])
                ->exists()) {
            return response()->json([
                'message' => "Import failed. This node id ({$nodeId}) already exists on the backend. Please double check properly to avoid node id duplication.",
            ], 422);
        }

        // ── Create node by node_id string ────────────────────────────────────
        $node = SkycableNode::create([
            'node_id'     => $nodeId,
            'area_id'     => $request->area_id,
            'name'        => $request->node_name,
            'status'      => 'pending',
            'source_file' => 'asbuilt',
        ]);

        $result = DB::transaction(function () use ($request, $node) {

            $createdPoles  = [];
            $updatedPoles  = [];
            $createdSpans  = [];
            $updatedSpans  = [];
            $errors        = [];

            $importedCoordinates = [];

            // pole_index (as string) → skycable_pole id. pole_index is the sole key
            // connecting spans to poles, so two poles can share a pole_code (e.g. "NPT")
            // and still be addressed unambiguously.
            $indexMap = [];

            // ── 1. Upsert Poles (keyed strictly by pole_index) ────────────────
            foreach ($request->poles as $idx => $poleData) {
                $this->upsertNodePole(
                    $node, $poleData, $idx,
                    $indexMap, $createdPoles, $updatedPoles, $errors, $importedCoordinates
                );
            }

            // ── 2. Upsert Spans + Summaries (resolved by pole_index) ──────────
            foreach (($request->spans ?? []) as $idx => $spanData) {
                $this->upsertNodeSpan(
                    $node, $spanData, $idx,
                    $indexMap, $createdSpans, $updatedSpans, $errors
                );
            }

            // ── 3. Update node metadata ───────────────────────────────────────
            $nodeUpdate = [
                'name'        => $request->node_name,
                'report_type' => 'full_report',
                'data_source' => 'json_import',
                'source_file' => 'asbuilt',
            ];

            if ($request->filled('region'))           $nodeUpdate['region']           = $request->region;
            if ($request->filled('province'))         $nodeUpdate['province']         = $request->province;
            if ($request->filled('city'))             $nodeUpdate['city']             = $request->city;
            if ($request->filled('barangay_name'))    $nodeUpdate['barangay_name']    = $request->barangay_name;
            if ($request->filled('subcontractor_id')) $nodeUpdate['subcontractor_id'] = $request->subcontractor_id;
            if ($request->filled('team_id'))          $nodeUpdate['team_id']          = $request->team_id;

            if (count($importedCoordinates) > 0) {
                $nodeUpdate['lat'] = collect($importedCoordinates)->avg('lat');
                $nodeUpdate['lng'] = collect($importedCoordinates)->avg('lng');
            }

            $node->update($nodeUpdate);
            $this->clearSkycableMapCaches();

            AuditLog::record('asbuilt_import', $node, null, [
                'poles_created' => count($createdPoles),
                'poles_updated' => count($updatedPoles),
                'spans_created' => count($createdSpans),
                'spans_updated' => count($updatedSpans),
                'errors'        => $errors,
            ]);

            return [
                'node' => [
                    'id'           => $node->id,
                    'node_id'      => $node->node_id,
                    'name'         => $node->name,
                    'region'       => $node->region,
                    'province'     => $node->province,
                    'city'         => $node->city,
                    'barangay_name' => $node->barangay_name,
                    'report_type'  => 'full_report',
                    'source_file'  => 'asbuilt',
                ],
                'poles_created' => $createdPoles,
                'poles_updated' => $updatedPoles,
                'spans_created' => $createdSpans,
                'spans_updated' => $updatedSpans,
                'total_poles'   => count($createdPoles) + count($updatedPoles),
                'total_spans'   => count($createdSpans) + count($updatedSpans),
                'errors'        => $errors,
            ];
        });

        // Warm cache immediately — next GET /skycable/nodes will be a Redis HIT
        \App\Services\CacheWarmer::nodes($request->area_id);
        \App\Services\CacheWarmer::spans($result['node']['id'] ?? 0);

        return response()->json([
            'message' => 'AsBuilt import completed.',
            'data'    => $result,
        ], 201);
    }

    /**
     * GET /asbuilt/sites
     */
    public function sites()
    {
        $areas = SkycableArea::withCount('nodes')
            ->orderBy('name')
            ->get()
            ->map(fn ($a) => [
                'id'         => $a->id,
                'name'       => $a->name,
                'node_count' => $a->nodes_count,
            ]);

        return response()->json($areas);
    }

    /**
     * GET /asbuilt/sites/{areaId}/nodes
     */
    public function nodesBySite(int $areaId)
    {
        $area = SkycableArea::findOrFail($areaId);

        $nodes = SkycableNode::where('area_id', $areaId)
            ->withCount('skycablePoles as pole_count')
            ->with(['subcontractor', 'team'])
            ->orderBy('name')
            ->get()
            ->map(fn ($n) => [
                'id'               => $n->id,
                'node_id'          => $n->node_id,
                'name'             => $n->name,
                'full_label'       => $n->full_label,
                'status'           => $n->status,
                'report_type'      => $n->report_type,
                'source_file'      => $n->source_file,
                'pole_count'       => $n->pole_count,
                'subcontractor_id' => $n->subcontractor_id,
                'subcontractor'    => $n->subcontractor?->name,
                'team_id'          => $n->team_id,
                'team'             => $n->team?->name,
            ]);

        return response()->json([
            'site'  => ['id' => $area->id, 'name' => $area->name],
            'nodes' => $nodes,
        ]);
    }

    /**
     * GET /asbuilt/node/{nodeId}
     */
    public function node(int $nodeId)
    {
        $node = SkycableNode::with([
            'area',
            'subcontractor',
            'team',
            'skycablePoles.pole',
            'spans.fromPole.pole',
            'spans.toPole.pole',
            'spans.summary',
        ])->findOrFail($nodeId);

        $poles = $node->skycablePoles->map(fn ($sp) => [
            'skycable_pole_id' => $sp->id,
            'pole_id'          => $sp->pole?->id,
            'pole_code'        => $sp->pole?->pole_code,
            'pole_index'       => $sp->pole_index,
            'latitude'         => $sp->pole?->lat,
            'longitude'        => $sp->pole?->lng,
            'status'           => $sp->status,
            'date_start'       => $sp->date_start,
            'finished_at'      => $sp->cleared_at,
            'duration'         => $sp->duration,
        ]);

        $spans = $node->spans->map(fn ($s) => [
            'span_id'          => $s->id,
            'span_code'        => $s->span_code,
            'from_pole_code'   => $s->fromPole?->pole?->pole_code,
            'from_pole_index'  => $s->fromPole?->pole_index,
            'to_pole_code'     => $s->toPole?->pole?->pole_code,
            'to_pole_index'    => $s->toPole?->pole_index,
            'strand_length'    => $s->strand_length,
            'number_of_runs'   => $s->number_of_runs,
            'expected_cable'   => $s->summary?->expected_cable ?? 0,
            'status'           => $s->status,
            'components'       => [
                'node'        => $s->summary?->expected_node        ?? 0,
                'amplifier'   => $s->summary?->expected_amplifier   ?? 0,
                'extender'    => $s->summary?->expected_extender    ?? 0,
                'tsc'         => $s->summary?->expected_tsc         ?? 0,
                'powersupply' => $s->summary?->expected_powersupply ?? 0,
                'ps_housing'  => $s->summary?->expected_ps_housing  ?? 0,
            ],
        ]);

        // Node-level component totals across all spans
        $totals = [
            'node'        => $node->spans->sum(fn ($s) => $s->summary?->expected_node        ?? 0),
            'amplifier'   => $node->spans->sum(fn ($s) => $s->summary?->expected_amplifier   ?? 0),
            'extender'    => $node->spans->sum(fn ($s) => $s->summary?->expected_extender    ?? 0),
            'tsc'         => $node->spans->sum(fn ($s) => $s->summary?->expected_tsc         ?? 0),
            'powersupply' => $node->spans->sum(fn ($s) => $s->summary?->expected_powersupply ?? 0),
            'ps_housing'  => $node->spans->sum(fn ($s) => $s->summary?->expected_ps_housing  ?? 0),
            'cable'       => $node->spans->sum(fn ($s) => $s->summary?->expected_cable       ?? 0),
        ];

        return response()->json([
            'node' => [
                'id'               => $node->id,
                'node_id'          => $node->node_id,
                'name'             => $node->name,
                'area'             => $node->area?->name,
                'region'           => $node->region,
                'province'         => $node->province,
                'city'             => $node->city,
                'barangay'         => $node->barangay_name,
                'report_type'      => $node->report_type,
                'source_file'      => $node->source_file,
                'status'           => $node->status,
                'subcontractor_id' => $node->subcontractor_id,
                'subcontractor'    => $node->subcontractor?->name,
                'team_id'          => $node->team_id,
                'team'             => $node->team?->name,
            ],
            'component_totals' => $totals,
            'poles' => $poles,
            'spans' => $spans,
        ]);
    }

    /**
     * POST /asbuilt/import-by-sequence
     *
     * Sequence-first import. Every pole carries a `sequence` (1-based integer).
     * Every span uses `from_sequence` / `to_sequence` to reference poles — no
     * pole_code disambiguation needed, no duplicate-code confusion.
     *
     * Payload example:
     *   poles: [{ sequence:1, pole_code:"PL-001", lat:14.53, lng:121.10 }, ...]
     *   spans: [{ from_sequence:1, to_sequence:2, strand_length:50.5 }, ...]
     */
    public function importBySequence(Request $request)
    {
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            if (! in_array($file->getMimeType(), ['application/json', 'text/plain', 'text/json'])) {
                return response()->json(['message' => 'Uploaded file must be a .json file.'], 422);
            }
            $decoded = json_decode(file_get_contents($file->getRealPath()), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['message' => 'Invalid JSON file: '.json_last_error_msg()], 422);
            }
            $request->merge($decoded);
        }

        $request->validate([
            'node_id'                       => 'required|string|max:100',
            'node_name'                     => 'required|string|max:255',
            'area_id'                       => 'required|exists:skycable_areas,id',
            'region'                        => 'nullable|string|max:255',
            'province'                      => 'nullable|string|max:255',
            'city'                          => 'nullable|string|max:255',
            'barangay_name'                 => 'nullable|string|max:255',
            'subcontractor_id'              => 'nullable|integer|exists:subcontractors,id',
            'team_id'                       => 'nullable|integer|exists:teams,id',
            'poles'                         => 'required|array|min:1',
            // pole_index is the unique key per pole within the node (e.g. "NPT-1", "CV8-001")
            // sequence is reserved for lineman teardown order — do not send from AsBuilt
            'poles.*.pole_index'            => 'required|string|max:50',
            'poles.*.pole_code'             => 'required|string|max:100',
            'poles.*.lat'                   => 'required_without:poles.*.latitude|numeric|between:-90,90',
            'poles.*.lng'                   => 'required_without:poles.*.longitude|numeric|between:-180,180',
            'poles.*.latitude'              => 'required_without:poles.*.lat|numeric|between:-90,90',
            'poles.*.longitude'             => 'required_without:poles.*.lng|numeric|between:-180,180',
            'spans'                         => 'nullable|array',
            'spans.*.from_pole_index'       => 'required_with:spans|string|max:50',
            'spans.*.to_pole_index'         => 'required_with:spans|string|max:50',
            'spans.*.strand_length'         => 'required_with:spans|numeric|min:0',
            'spans.*.number_of_runs'        => 'required_with:spans|integer|min:1',
            'spans.*.components'            => 'nullable|array',
            'spans.*.components.node'       => 'nullable|integer|min:0',
            'spans.*.components.amplifier'  => 'nullable|integer|min:0',
            'spans.*.components.extender'   => 'nullable|integer|min:0',
            'spans.*.components.tsc'        => 'nullable|integer|min:0',
            'spans.*.components.powersupply'=> 'nullable|integer|min:0',
            'spans.*.components.ps_housing' => 'nullable|integer|min:0',
        ]);

        // ── Reject duplicate node_id within the same area (case-insensitive) ──
        $nodeId = strtoupper(trim($request->node_id));
        if (SkycableNode::where('area_id', $request->area_id)
                ->whereRaw('UPPER(node_id) = ?', [$nodeId])
                ->exists()) {
            return response()->json([
                'message' => "Import failed. This node id ({$request->node_id}) already exists on the backend. Please double check properly to avoid node id duplication.",
            ], 422);
        }

        // ── Create node ───────────────────────────────────────────────────────
        $node = SkycableNode::newModelInstance([
            'node_id' => $nodeId,
            'area_id' => $request->area_id,
        ]);
        $node->fill(array_filter([
            'name'             => trim($request->node_name),
            'region'           => $request->region           ? trim($request->region)        : null,
            'province'         => $request->province         ? trim($request->province)      : null,
            'city'             => $request->city             ? trim($request->city)          : null,
            'barangay_name'    => $request->barangay_name    ? trim($request->barangay_name) : null,
            'subcontractor_id' => $request->subcontractor_id ?: null,
            'team_id'          => $request->team_id          ?: null,
            'report_type'      => 'full_report',
            'source_file'      => 'asbuilt',
        ], fn ($v) => $v !== null));
        $node->save();

        $result = DB::transaction(function () use ($request, $node) {

            $createdPoles  = [];
            $updatedPoles  = [];
            $createdSpans  = [];
            $updatedSpans  = [];
            $errors        = [];

            // pole_index (as string, e.g. "NPT-1", "CV8-001") → skycable_pole id.
            // pole_index is the sole key connecting spans to poles.
            $indexMap = [];
            $importedCoordinates = [];

            // ── 1. Upsert Poles (keyed strictly by pole_index) ────────────────
            foreach ($request->poles as $idx => $poleData) {
                $this->upsertNodePole(
                    $node, $poleData, $idx,
                    $indexMap, $createdPoles, $updatedPoles, $errors, $importedCoordinates
                );
            }

            // ── 2. Upsert Spans + Summaries (resolved by pole_index) ──────────
            foreach (($request->spans ?? []) as $idx => $spanData) {
                $this->upsertNodeSpan(
                    $node, $spanData, $idx,
                    $indexMap, $createdSpans, $updatedSpans, $errors
                );
            }

            AuditLog::record('create', $node, null, $node->toArray());
            $this->clearSkycableMapCaches();

            return [
                'node'          => $node->fresh(),
                'poles_created' => $createdPoles,
                'poles_updated' => $updatedPoles,
                'spans_created' => $createdSpans,
                'spans_updated' => $updatedSpans,
                'total_poles'   => count($createdPoles) + count($updatedPoles),
                'total_spans'   => count($createdSpans) + count($updatedSpans),
                'errors'        => $errors,
            ];
        });

        $n = $result['node'];

        return response()->json([
            'message' => 'AsBuilt sequence import completed.',
            'data'    => [
                'node' => [
                    'id'           => $n->id,
                    'node_id'      => $n->node_id,
                    'name'         => $n->name,
                    'region'       => $n->region,
                    'province'     => $n->province,
                    'city'         => $n->city,
                    'barangay_name'=> $n->barangay_name,
                    'report_type'  => $n->report_type,
                    'source_file'  => $n->source_file,
                ],
                'poles_created' => $result['poles_created'],
                'poles_updated' => $result['poles_updated'],
                'spans_created' => $result['spans_created'],
                'spans_updated' => $result['spans_updated'],
                'total_poles'   => $result['total_poles'],
                'total_spans'   => $result['total_spans'],
                'errors'        => $result['errors'],
            ],
        ], 201);
    }

    /**
     * POST /asbuilt/backfill-span-codes
     * One-time utility — generates span_code for all existing spans that don't have one.
     */
    public function backfillSpanCodes(): \Illuminate\Http\JsonResponse
    {
        $spans = SkycableSpan::whereNull('span_code')
            ->with('node')
            ->get();

        $updated = 0;
        foreach ($spans as $span) {
            if (! $span->node) continue;
            $span->update(['span_code' => $this->generateSpanCode($span->node)]);
            $updated++;
        }

        return response()->json([
            'message' => "Backfilled {$updated} span codes.",
            'updated' => $updated,
        ]);
    }

    /**
     * GET /asbuilt/subcontractors
     */
    public function subcontractors(): \Illuminate\Http\JsonResponse
    {
        $subs = \App\Models\Subcontractor::orderBy('name')
            ->get(['id', 'name']);

        return response()->json($subs);
    }

    /**
     * GET /asbuilt/teams?subcontractor_id={id}
     */
    public function teams(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $query = \App\Models\Team::orderBy('name');

        if ($request->filled('subcontractor_id')) {
            $query->where('subcontractor_id', $request->subcontractor_id);
        }

        return response()->json($query->get(['id', 'name', 'subcontractor_id']));
    }

    private function generateSpanCode(\App\Models\SkycableNode $node): string
    {
        // Build prefix from city → initials of each word, uppercase, max 4 chars
        $city   = trim($node->city ?? $node->name ?? '');
        $words  = preg_split('/\s+/', $city);
        $prefix = strtoupper(implode('', array_map(fn ($w) => $w[0] ?? '', $words)));
        $prefix = substr($prefix ?: 'SPN', 0, 4);

        // Sequential counter globally across ALL spans with this prefix
        // prevents unique constraint violation when multiple nodes share the same city initials
        $last = SkycableSpan::whereNotNull('span_code')
            ->where('span_code', 'like', "{$prefix}-%")
            ->orderByRaw('LENGTH(span_code) DESC, span_code DESC')
            ->value('span_code');

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }

        return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    private function normalizeCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        return round((float) $value, 7);
    }

    /**
     * Upsert one pole into a node, keyed strictly by (node_id, pole_index).
     *
     * pole_index is normalized to an uppercase string so the integer payload of
     * /import and the string payload of /import-by-sequence map identically. Each
     * distinct pole_index gets its OWN poles row (own lat/lng) — two poles that share
     * a pole_code (e.g. "NPT") therefore render as two distinct pins and the span
     * between them connects correctly. pole_code is stored but never used to match.
     *
     * Records the resulting skycable_pole id in $indexMap keyed by pole_index.
     */
    private function upsertNodePole(
        SkycableNode $node,
        array $poleData,
        int $idx,
        array &$indexMap,
        array &$createdPoles,
        array &$updatedPoles,
        array &$errors,
        array &$importedCoordinates
    ): void {
        $poleIndex = isset($poleData['pole_index']) ? strtoupper(trim((string) $poleData['pole_index'])) : '';
        $code      = strtoupper(trim((string) ($poleData['pole_code'] ?? '')));
        $lat       = $this->normalizeCoordinate($poleData['lat'] ?? $poleData['latitude'] ?? null);
        $lng       = $this->normalizeCoordinate($poleData['lng'] ?? $poleData['longitude'] ?? null);

        if ($poleIndex === '') {
            $errors[] = "poles[{$idx}]: pole_index is required";
            return;
        }
        if ($code === '') {
            $errors[] = "poles[{$idx}]: pole_code is empty";
            return;
        }
        // Prevent duplicate pole_index within this import batch
        if (isset($indexMap[$poleIndex])) {
            $errors[] = "poles[{$idx}]: duplicate pole_index '{$poleIndex}'";
            return;
        }

        // Re-import match is by (node_id, pole_index) only — never by pole_code.
        $skycablePole = SkycablePole::with('pole')
            ->where('node_id', $node->id)
            ->where('pole_index', $poleIndex)
            ->first();

        if ($skycablePole) {
            $pole = $skycablePole->pole;
            if (! $pole) {
                $pole = Pole::create(['pole_code' => $code, 'lat' => $lat, 'lng' => $lng]);
                $skycablePole->update(['pole_id' => $pole->id]);
            } else {
                $updates = [];
                if ($pole->pole_code !== $code) $updates['pole_code'] = $code;
                if ($lat !== null && (float) $pole->lat !== $lat) $updates['lat'] = $lat;
                if ($lng !== null && (float) $pole->lng !== $lng) $updates['lng'] = $lng;
                if ($updates) $pole->update($updates);
            }
            $updatedPoles[] = $code;
        } else {
            // Each pole_index gets its own poles row — no firstOrCreate by pole_code.
            $pole = Pole::create(['pole_code' => $code, 'lat' => $lat, 'lng' => $lng]);
            $skycablePole = SkycablePole::create([
                'node_id'    => $node->id,
                'pole_id'    => $pole->id,
                'pole_index' => $poleIndex,
            ]);
            $createdPoles[] = $code;
        }

        if ($lat !== null && $lng !== null) {
            $importedCoordinates[] = ['lat' => $lat, 'lng' => $lng];
        }

        $indexMap[$poleIndex] = $skycablePole->id;
    }

    /**
     * Upsert one span + its summary, resolving both endpoints strictly by pole_index
     * via $indexMap. from_pole_code / to_pole_code in the payload are descriptive only
     * and are never used to look up poles.
     */
    private function upsertNodeSpan(
        SkycableNode $node,
        array $spanData,
        int $idx,
        array $indexMap,
        array &$createdSpans,
        array &$updatedSpans,
        array &$errors
    ): void {
        $fromKey = isset($spanData['from_pole_index']) ? strtoupper(trim((string) $spanData['from_pole_index'])) : '';
        $toKey   = isset($spanData['to_pole_index'])   ? strtoupper(trim((string) $spanData['to_pole_index']))   : '';

        if ($fromKey === '') {
            $errors[] = "spans[{$idx}]: from_pole_index is required";
            return;
        }
        if ($toKey === '') {
            $errors[] = "spans[{$idx}]: to_pole_index is required";
            return;
        }

        $fromSkId = $indexMap[$fromKey] ?? null;
        $toSkId   = $indexMap[$toKey]   ?? null;

        if (! $fromSkId) {
            $errors[] = "spans[{$idx}]: from_pole_index '{$fromKey}' not found in poles list";
            return;
        }
        if (! $toSkId) {
            $errors[] = "spans[{$idx}]: to_pole_index '{$toKey}' not found in poles list";
            return;
        }
        if ($fromSkId === $toSkId) {
            $errors[] = "spans[{$idx}]: from and to poles must be different";
            return;
        }

        $strandLength  = isset($spanData['strand_length'])  ? (float) $spanData['strand_length'] : null;
        $numberOfRuns  = isset($spanData['number_of_runs']) ? max(1, (int) $spanData['number_of_runs']) : 1;
        $expectedCable = $strandLength !== null ? round($strandLength * $numberOfRuns, 2) : 0;
        $comp          = $spanData['components'] ?? [];

        $span = SkycableSpan::firstOrCreate(
            [
                'node_id'      => $node->id,
                'from_pole_id' => $fromSkId,
                'to_pole_id'   => $toSkId,
            ],
            [
                'strand_length'  => $strandLength,
                'number_of_runs' => $numberOfRuns,
                'span_code'      => $this->generateSpanCode($node),
                'status'         => 'pending',
            ]
        );

        if ($span->wasRecentlyCreated) {
            $createdSpans[] = $span->span_code ?? "{$fromKey}→{$toKey}";
        } else {
            $span->update([
                'strand_length'  => $strandLength  ?? $span->strand_length,
                'number_of_runs' => $numberOfRuns  ?? $span->number_of_runs,
            ]);
            $updatedSpans[] = $span->span_code ?? "{$fromKey}→{$toKey}";
        }

        SkycableSpanSummary::updateOrCreate(
            ['span_id' => $span->id],
            [
                'node_id'              => $node->id,
                'expected_cable'       => $expectedCable,
                'expected_node'        => $comp['node']        ?? 0,
                'expected_amplifier'   => $comp['amplifier']   ?? 0,
                'expected_extender'    => $comp['extender']    ?? 0,
                'expected_tsc'         => $comp['tsc']         ?? 0,
                'expected_powersupply' => $comp['powersupply'] ?? 0,
                'expected_ps_housing'  => $comp['ps_housing']  ?? 0,
            ]
        );
    }

    private function clearSkycableMapCaches(): void
    {
        Cache::forget('skycable_all_poles');
        Cache::forget('nodes_index_50_p1');
        Cache::forget('nodes_index_100_p1');
        Cache::forget('nodes_index_200_p1');
    }
}
