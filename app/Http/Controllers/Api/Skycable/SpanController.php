<?php

namespace App\Http\Controllers\Api\Skycable;

use App\Http\Concerns\CachesSkycableResponses;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Pole;
use App\Models\SkycableNode;
use App\Models\SkycablePole;
use App\Models\SkycableSpan;
use App\Models\SkycableSpanSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpanController extends Controller
{
    use CachesSkycableResponses;

    public function index(Request $request)
    {
        $perPage  = (int) ($request->per_page ?? 0);
        $nodeId   = $request->node_id;
        $status   = $request->status;
        $search   = $request->search;

        $query = SkycableSpan::with(['node', 'fromPole.pole', 'toPole.pole', 'summary'])
            ->when($nodeId,  fn ($q) => $q->where('node_id', $nodeId))
            ->when($status,  fn ($q) => $q->where('status', $status))
            ->when($search,  fn ($q) => $q->where('span_code', 'like', "%{$search}%"))
            ->orderBy('id', 'desc');

        if ($perPage > 0) {
            return response()->json($query->paginate($perPage));
        }

        $cacheKey = "spans.index" . ($nodeId ? ".node{$nodeId}" : "") . ($status ? ".{$status}" : "");
        return $this->skycableCachedJson($cacheKey, 120, fn () => $query->get(), $request);
    }

    /**
     * GET /skycable/nodes/{node}/spans
     * All spans belonging to a specific node, with full relations.
     */
    public function byNode(SkycableNode $node, Request $request)
    {
        $status = $request->status;

        $spans = SkycableSpan::with(['fromPole.pole', 'toPole.pole', 'summary'])
            ->where('node_id', $node->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->whereNotIn('status', ['superseded'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'node'  => $node->only(['id', 'name', 'full_label']),
            'spans' => $spans,
            'stats' => $this->buildStats($spans),
        ]);
    }

    /**
     * GET /skycable/spans/stats
     * Aggregate statistics — optionally scoped to a node.
     */
    public function stats(Request $request)
    {
        $nodeId = $request->node_id;

        $spans = SkycableSpan::with('summary')
            ->when($nodeId, fn ($q) => $q->where('node_id', $nodeId))
            ->whereNotIn('status', ['superseded', 'cancelled'])
            ->get();

        return response()->json($this->buildStats($spans));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'node_id'          => 'required|exists:skycable_nodes,id',
            'from_pole_id'     => 'required|exists:skycable_poles,id',
            'to_pole_id'       => 'required|exists:skycable_poles,id|different:from_pole_id',
            'span_code'        => 'nullable|string|max:100',
            'strand_length'    => 'nullable|numeric|min:0',
            'number_of_runs'   => 'nullable|integer|min:0',
            'actual_cable'     => 'nullable|numeric|min:0',
            'nodes_count'      => 'nullable|integer|min:0',
            'amplifier'        => 'nullable|integer|min:0',
            'extender'         => 'nullable|integer|min:0',
            'tsc'              => 'nullable|integer|min:0',
            'power_supply'     => 'nullable|integer|min:0',
            'power_supply_case'=> 'nullable|integer|min:0',
            'powersupply'      => 'nullable|integer|min:0',
            'ps_housing'       => 'nullable|integer|min:0',
        ]);

        $activeDuplicate = SkycableSpan::where('from_pole_id', $data['from_pole_id'])
            ->where('to_pole_id', $data['to_pole_id'])
            ->first();
        if ($activeDuplicate) {
            return response()->json(['message' => 'A span between these two poles already exists.'], 422);
        }

        // skycable_spans has a unique(from_pole_id, to_pole_id) index that isn't soft-delete-aware,
        // so a previously deleted span between this exact pair still occupies that slot — restore
        // and reuse it instead of inserting, otherwise the insert throws a duplicate-key error.
        $trashed = SkycableSpan::onlyTrashed()
            ->where('from_pole_id', $data['from_pole_id'])
            ->where('to_pole_id', $data['to_pole_id'])
            ->first();

        if ($trashed) {
            $trashed->restore();
            $span = $trashed;
            $span->update([
                'node_id'        => $data['node_id'],
                'span_code'      => $data['span_code'] ?? null,
                'strand_length'  => $data['strand_length'] ?? null,
                'number_of_runs' => $data['number_of_runs'] ?? null,
                'actual_cable'   => $data['actual_cable'] ?? null,
                'status'         => 'pending',
                'completed_at'   => null,
            ]);
        } else {
            $span = SkycableSpan::create([
                'node_id'        => $data['node_id'],
                'from_pole_id'   => $data['from_pole_id'],
                'to_pole_id'     => $data['to_pole_id'],
                'span_code'      => $data['span_code'] ?? null,
                'strand_length'  => $data['strand_length'] ?? null,
                'number_of_runs' => $data['number_of_runs'] ?? null,
                'actual_cable'   => $data['actual_cable'] ?? null,
            ]);
        }

        $this->syncComponents($span, $data);

        $poleChanges = $this->refreshPoleStatuses($span);

        AuditLog::record('created', $span, null, array_merge($span->toArray(), ['pole_status_changes' => $poleChanges]));
        \App\Services\CacheWarmer::spans($span->node_id);

        return response()->json([
            'span'                => $span->load(['fromPole.pole', 'toPole.pole', 'summary']),
            'pole_status_changes' => $poleChanges,
        ], 201);
    }

    public function show(SkycableSpan $span)
    {
        return $this->skycableCachedJson("spans.show.{$span->id}", 120, function () use ($span) {
            return $span->load(['node', 'fromPole.pole', 'toPole.pole', 'summary', 'teardownReports']);
        });
    }

    public function update(Request $request, SkycableSpan $span)
    {
        $data = $request->validate([
            'from_pole_id'     => 'sometimes|required|exists:skycable_poles,id|different:to_pole_id',
            'to_pole_id'       => 'sometimes|required|exists:skycable_poles,id|different:from_pole_id',
            'span_code'        => 'sometimes|nullable|string|max:100',
            'strand_length'    => 'sometimes|nullable|numeric|min:0',
            'number_of_runs'   => 'sometimes|nullable|integer|min:0',
            'actual_cable'     => 'sometimes|nullable|numeric|min:0',
            'status'           => 'sometimes|in:pending,in_progress,completed,cancelled',
            'nodes_count'      => 'sometimes|nullable|integer|min:0',
            'amplifier'        => 'sometimes|nullable|integer|min:0',
            'extender'         => 'sometimes|nullable|integer|min:0',
            'tsc'              => 'sometimes|nullable|integer|min:0',
            'power_supply'     => 'sometimes|nullable|integer|min:0',
            'power_supply_case'=> 'sometimes|nullable|integer|min:0',
            'powersupply'      => 'sometimes|nullable|integer|min:0',
            'ps_housing'       => 'sometimes|nullable|integer|min:0',
        ]);

        $nextFromPoleId = array_key_exists('from_pole_id', $data) ? $data['from_pole_id'] : $span->from_pole_id;
        $nextToPoleId = array_key_exists('to_pole_id', $data) ? $data['to_pole_id'] : $span->to_pole_id;

        if ($nextFromPoleId !== $span->from_pole_id || $nextToPoleId !== $span->to_pole_id) {
            $activeDuplicate = SkycableSpan::where('id', '!=', $span->id)
                ->where('from_pole_id', $nextFromPoleId)
                ->where('to_pole_id', $nextToPoleId)
                ->first();
            if ($activeDuplicate) {
                return response()->json(['message' => 'A span between these two poles already exists.'], 422);
            }

            $trashedDuplicate = SkycableSpan::onlyTrashed()
                ->where('id', '!=', $span->id)
                ->where('from_pole_id', $nextFromPoleId)
                ->where('to_pole_id', $nextToPoleId)
                ->first();
            if ($trashedDuplicate) {
                return response()->json(['message' => 'A deleted span between these two poles already exists. Restore that span instead of reusing the same pair.'], 422);
            }
        }

        $old = $span->toArray();
        $affectedPoleIds = array_unique(array_filter([
            $span->from_pole_id,
            $span->to_pole_id,
            $nextFromPoleId,
            $nextToPoleId,
        ]));

        $span->update([
            'from_pole_id'   => $nextFromPoleId,
            'to_pole_id'     => $nextToPoleId,
            'span_code'      => array_key_exists('span_code', $data) ? $data['span_code'] : $span->span_code,
            'strand_length'  => $data['strand_length']  ?? $span->strand_length,
            'number_of_runs' => $data['number_of_runs'] ?? $span->number_of_runs,
            'actual_cable'   => $data['actual_cable']   ?? $span->actual_cable,
            'status'         => $data['status']         ?? $span->status,
        ]);

        $this->syncComponents($span, $data);
        $poleChanges = $this->refreshPoleStatusesByIds($affectedPoleIds);

        AuditLog::record('updated', $span, $old, array_merge($span->toArray(), ['pole_status_changes' => $poleChanges]));
        \App\Services\CacheWarmer::spans($span->node_id);

        return response()->json($span->load(['fromPole.pole', 'toPole.pole', 'summary']));
    }

    /**
     * PATCH /skycable/spans/{span}/status
     * Quick single-field status update without touching other fields.
     */
    public function updateStatus(Request $request, SkycableSpan $span)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,in_progress,completed,cancelled',
        ]);

        $old = $span->only(['status']);
        $span->update(['status' => $data['status']]);

        if ($data['status'] === 'completed') {
            $span->update(['completed_at' => now()]);
        }

        $poleChanges = $this->refreshPoleStatuses($span);

        AuditLog::record('status_changed', $span, $old, ['status' => $data['status']]);
        \App\Services\CacheWarmer::spans($span->node_id);

        return response()->json([
            'span'                => $span->load(['fromPole.pole', 'toPole.pole', 'summary']),
            'pole_status_changes' => $poleChanges,
        ]);
    }

    public function destroy(SkycableSpan $span)
    {
        AuditLog::record('deleted', $span, $span->toArray(), null);
        $span->delete();
        $this->bumpSkycableCacheVersion();

        return response()->json(['message' => 'Span deleted.']);
    }

    /**
     * Split a span by inserting a new pole between from_pole and to_pole.
     *
     * POST /skycable/spans/{span}/split
     * Body: { pole_name: string, idempotency_key?: string }
     */
    public function split(Request $request, SkycableSpan $span)
    {
        $request->validate([
            'pole_name'       => 'required|string|max:100',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        if ($key = $request->input('idempotency_key')) {
            $existing = SkycableSpan::where('idempotency_key', $key)->first();
            if ($existing) {
                $spanA = SkycableSpan::where('idempotency_key', $key . '_a')->with(['fromPole.pole', 'toPole.pole', 'summary'])->first();
                $spanB = SkycableSpan::where('idempotency_key', $key . '_b')->with(['fromPole.pole', 'toPole.pole', 'summary'])->first();
                $newPole = $spanA?->toPole?->pole;
                return response()->json([
                    'new_pole' => $newPole,
                    'span_a'   => $spanA,
                    'span_b'   => $spanB,
                ], 200);
            }
        }

        if ($span->status === 'superseded') {
            return response()->json(['message' => 'This span has already been split.'], 409);
        }

        $result = DB::transaction(function () use ($span, $request, $key) {
            $poleName = $request->input('pole_name');

            $newPole = Pole::create([
                'pole_code' => $poleName,
                'lat'       => null,
                'lng'       => null,
                // Same as PoleController::store() — this pole only ever comes from
                // a lineman inserting it in the field via mobile, never AsBuilt import.
                'is_manual' => true,
            ]);

            $nextSeq = SkycablePole::where('node_id', $span->node_id)->max('sequence') + 1;
            $newSkycablePole = SkycablePole::create([
                'node_id'  => $span->node_id,
                'pole_id'  => $newPole->id,
                'sequence' => $nextSeq,
            ]);

            $spanA = SkycableSpan::create([
                'node_id'          => $span->node_id,
                'from_pole_id'     => $span->from_pole_id,
                'to_pole_id'       => $newSkycablePole->id,
                'span_code'        => $span->span_code ? $span->span_code . '-A' : null,
                'strand_length'    => $span->strand_length ? round($span->strand_length / 2, 2) : null,
                'number_of_runs'   => $span->number_of_runs,
                'actual_cable'     => null,
                'status'           => 'pending',
                'idempotency_key'  => $key ? $key . '_a' : null,
            ]);

            $spanB = SkycableSpan::create([
                'node_id'          => $span->node_id,
                'from_pole_id'     => $newSkycablePole->id,
                'to_pole_id'       => $span->to_pole_id,
                'span_code'        => $span->span_code ? $span->span_code . '-B' : null,
                'strand_length'    => $span->strand_length ? round($span->strand_length / 2, 2) : null,
                'number_of_runs'   => $span->number_of_runs,
                'actual_cable'     => null,
                'status'           => 'pending',
                'idempotency_key'  => $key ? $key . '_b' : null,
            ]);

            $parentSummary = SkycableSpanSummary::where('span_id', $span->id)->first();
            foreach ([$spanA->id, $spanB->id] as $sid) {
                SkycableSpanSummary::create([
                    'span_id'              => $sid,
                    'node_id'              => $span->node_id,
                    'expected_cable'       => $parentSummary?->expected_cable ?? 0,
                    'expected_node'        => $parentSummary?->expected_node ?? 0,
                    'expected_amplifier'   => $parentSummary?->expected_amplifier ?? 0,
                    'expected_extender'    => $parentSummary?->expected_extender ?? 0,
                    'expected_tsc'         => $parentSummary?->expected_tsc ?? 0,
                    'expected_powersupply' => $parentSummary?->expected_powersupply ?? 0,
                    'expected_ps_housing'  => $parentSummary?->expected_ps_housing ?? 0,
                ]);
            }

            $span->update(['status' => 'superseded']);

            AuditLog::record('split', $span, $span->toArray(), [
                'new_pole_id' => $newPole->id,
                'span_a_id'   => $spanA->id,
                'span_b_id'   => $spanB->id,
            ]);

            return [
                'new_pole' => $newPole->fresh(),
                'span_a'   => $spanA->load(['fromPole.pole', 'toPole.pole', 'summary']),
                'span_b'   => $spanB->load(['fromPole.pole', 'toPole.pole', 'summary']),
            ];
        });

        $poleChanges = array_merge(
            $this->refreshPoleStatuses($result['span_a']),
            $this->refreshPoleStatuses($result['span_b']),
        );

        $this->bumpSkycableCacheVersion();

        return response()->json(array_merge($result, ['pole_status_changes' => $poleChanges]), 201);
    }

    public function updateComponents(Request $request, SkycableSpan $span)
    {
        $request->validate([
            'expected_node'       => 'nullable|integer|min:0',
            'expected_amplifier'  => 'nullable|integer|min:0',
            'expected_extender'   => 'nullable|integer|min:0',
            'expected_tsc'        => 'nullable|integer|min:0',
            'expected_powersupply'=> 'nullable|integer|min:0',
            'expected_ps_housing' => 'nullable|integer|min:0',
            'expected_cable'      => 'nullable|numeric|min:0',
        ]);

        SkycableSpanSummary::updateOrCreate(
            ['span_id' => $span->id],
            array_merge(
                ['node_id' => $span->node_id],
                array_filter($request->only([
                    'expected_node', 'expected_amplifier', 'expected_extender',
                    'expected_tsc', 'expected_powersupply', 'expected_ps_housing', 'expected_cable',
                ]), fn($v) => !is_null($v))
            )
        );

        AuditLog::record('updated', $span, null, ['components_updated' => true]);
        $this->bumpSkycableCacheVersion();

        return response()->json($span->load('summary'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildStats($spans): array
    {
        $active = $spans->whereNotIn('status', ['superseded', 'cancelled']);

        return [
            'total'            => $active->count(),
            'pending'          => $active->where('status', 'pending')->count(),
            'in_progress'      => $active->where('status', 'in_progress')->count(),
            'completed'        => $active->where('status', 'completed')->count(),
            'cancelled'        => $spans->where('status', 'cancelled')->count(),
            'total_cable_m'    => round($active->sum(fn ($s) => $s->summary?->expected_cable ?? 0), 2),
            'completed_cable_m'=> round($active->where('status', 'completed')->sum(fn ($s) => $s->summary?->expected_cable ?? 0), 2),
            'total_strand_m'   => round($active->sum('strand_length'), 2),
            'components' => [
                'nodes'       => $active->sum(fn ($s) => $s->summary?->expected_node ?? 0),
                'amplifiers'  => $active->sum(fn ($s) => $s->summary?->expected_amplifier ?? 0),
                'extenders'   => $active->sum(fn ($s) => $s->summary?->expected_extender ?? 0),
                'tsc'         => $active->sum(fn ($s) => $s->summary?->expected_tsc ?? 0),
                'powersupply' => $active->sum(fn ($s) => $s->summary?->expected_powersupply ?? 0),
                'ps_housing'  => $active->sum(fn ($s) => $s->summary?->expected_ps_housing ?? 0),
            ],
        ];
    }

    private function refreshPoleStatuses(SkycableSpan $span): array
    {
        return $this->refreshPoleStatusesByIds([$span->from_pole_id, $span->to_pole_id]);
    }

    private function refreshPoleStatusesByIds(array $skycablePoleIds): array
    {
        $skycablePoleIds = array_values(array_unique(array_filter($skycablePoleIds)));
        $changed = [];

        foreach ($skycablePoleIds as $spId) {
            $skycablePole = SkycablePole::with('pole')->find($spId);
            if (!$skycablePole?->pole) continue;

            $allSpans = SkycableSpan::where(function ($q) use ($spId) {
                $q->where('from_pole_id', $spId)->orWhere('to_pole_id', $spId);
            })->whereNotIn('status', ['superseded', 'cancelled'])->get();

            $poleCode = $skycablePole->pole->pole_code;
            $previousStatus = $skycablePole->pole->skycable_status;

            if ($allSpans->isEmpty()) {
                $newStatus = 'cleared';
                $reason = 'no active spans remain';
            } else {
                $pendingSpans   = $allSpans->where('status', '!=', 'completed');
                $completedSpans = $allSpans->where('status', 'completed');

                $hasPending   = $pendingSpans->isNotEmpty();
                $hasCompleted = $completedSpans->isNotEmpty();

                $newStatus = match (true) {
                    $hasPending && !$hasCompleted => 'pending',
                    $hasPending &&  $hasCompleted => 'in_progress',
                    default                       => 'cleared',
                };
                $reason = "has {$pendingSpans->count()} pending + {$completedSpans->count()} completed spans";
            }

            if ($previousStatus !== $newStatus) {
                $skycablePole->pole->update(['skycable_status' => $newStatus]);
                $changed[$poleCode] = [
                    'from'   => $previousStatus,
                    'to'     => $newStatus,
                    'reason' => $reason,
                ];
            }
        }

        return $changed;
    }

    private function syncComponents(SkycableSpan $span, array $data): void
    {
        $strand = (float) ($span->strand_length ?? 0);
        $runs   = (int)   ($span->number_of_runs ?? 1);
        $expectedCable = $strand * $runs;

        $fields = [
            'expected_cable'       => $expectedCable,
            'expected_node'        => (int) ($data['nodes_count']      ?? $data['expected_node']      ?? 0),
            'expected_amplifier'   => (int) ($data['amplifier']         ?? $data['expected_amplifier']  ?? 0),
            'expected_extender'    => (int) ($data['extender']          ?? $data['expected_extender']   ?? 0),
            'expected_tsc'         => (int) ($data['tsc']               ?? $data['expected_tsc']        ?? 0),
            'expected_powersupply' => (int) ($data['power_supply']      ?? $data['powersupply']         ?? 0),
            'expected_ps_housing'  => (int) ($data['power_supply_case'] ?? $data['ps_housing']          ?? 0),
        ];

        SkycableSpanSummary::updateOrCreate(
            ['span_id' => $span->id],
            array_merge(['node_id' => $span->node_id], $fields)
        );
    }
}
