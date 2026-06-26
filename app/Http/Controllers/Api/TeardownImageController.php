<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\StoresPhotos;
use App\Http\Controllers\Controller;
use App\Models\Pole;
use App\Models\PoleTeardownImage;
use App\Models\SkycableArea;
use App\Models\SkycableNode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeardownImageController extends Controller
{
    use StoresPhotos;

    public function upload(Request $request)
    {
        $request->validate([
            'report_id'      => 'nullable|integer',
            'pole_id'        => 'required|integer',
            'area_id'        => 'nullable|integer',
            'node_id'        => 'nullable|integer',
            'pole_code'      => 'required|string',
            'image_type'     => 'required|string|in:before,after,pole_tag,bunching',
            'span_id'        => 'nullable|integer',
            'to_pole_id'     => 'nullable|integer',
            'pole_tag'       => 'nullable|string',
            'inventory_type' => 'required|string|in:skycable,globe',
            'image'          => 'required|image|max:10240', // 10MB max
        ]);

        $node = $request->node_id ? SkycableNode::find($request->node_id) : null;
        $area = $request->area_id ? SkycableArea::find($request->area_id) : ($node ? $node->area : null);
        $areaId = $area ? $area->id : $request->area_id;

        $areaName  = $this->sanitizePath($area ? $area->name : 'Unknown_Area');
        $nodeName  = $this->sanitizePath($node ? $node->name : 'Unknown_Node');
        $poleCode  = $this->sanitizePath($request->pole_code);
        $poleId    = $request->pole_id;
        $imageType = $request->image_type;

        if ($imageType === 'bunching') {
            // bunching/{node_id}/{from_pole_id}_to_{to_pole_id}/bunching.jpg
            $nodeIdSlug   = $this->sanitizePath($request->node_id    ?? 'unknown');
            $fromPoleSlug = $this->sanitizePath($request->pole_id    ?? 'unknown');
            $toPoleSlug   = $this->sanitizePath($request->to_pole_id ?? 'unknown');
            $folderPath   = "bunching/{$nodeIdSlug}/{$fromPoleSlug}_to_{$toPoleSlug}";
            $fileName     = "bunching.jpg";
        } else {
            // {area}/{node}/{pole_code}/{pole_code}_before.jpg  (or after / poletag)
            // Poles still using the default "NPT" code aren't unique per node,
            // so suffix with the pole ID to avoid two NPT poles overwriting
            // each other's photos. Renaming the pole away from "NPT" later
            // naturally moves future uploads to a folder named after the new code.
            $typeSlug  = $imageType === 'pole_tag' ? 'poletag' : $imageType;
            $slugName  = strcasecmp($poleCode, 'NPT') === 0 ? "{$poleCode}({$poleId})" : $poleCode;
            $folderPath = "{$areaName}/{$nodeName}/{$slugName}";
            $fileName   = "{$slugName}_{$typeSlug}.jpg";
        }

        $fullPath = "{$folderPath}/{$fileName}";

        $storedPath = $this->storePhoto($request->file('image'), 'teardown', 1280, $fullPath);

        // Batch-before uploads (no report_id) → upsert so retakes replace the same record + file.
        // Regular teardown uploads (with report_id) → always create a new record per submission.
        if ($request->report_id === null) {
            $imageData = PoleTeardownImage::updateOrCreate(
                [
                    'pole_id'        => $request->pole_id,
                    'image_type'     => $request->image_type,
                    'inventory_type' => $request->inventory_type,
                    'report_id'      => null,
                ],
                [
                    'area_id'   => $request->area_id,
                    'node_id'   => $request->node_id,
                    'pole_code' => $request->pole_code,
                    'pole_tag'  => $request->pole_tag,
                    'file_path' => $storedPath,
                    'locked'    => $request->boolean('lock', false),
                ]
            );
        } else {
            $imageData = PoleTeardownImage::create([
                'report_id'      => $request->report_id,
                'pole_id'        => $request->pole_id,
                'area_id'        => $request->area_id,
                'node_id'        => $request->node_id,
                'pole_code'      => $request->pole_code,
                'image_type'     => $request->image_type,
                'pole_tag'       => $request->pole_tag,
                'file_path'      => $storedPath,
                'inventory_type' => $request->inventory_type,
                'locked'         => false,
            ]);
        }

        return response()->json([
            'message' => 'Image uploaded successfully',
            'data'    => $imageData
        ], 201);
    }

    public function fetchByNode(Request $request, $nodeId)
    {
        $images = PoleTeardownImage::where('node_id', $nodeId)
            ->when($request->inventory_type, fn($q) => $q->where('inventory_type', $request->inventory_type))
            ->when($request->pole_id,        fn($q) => $q->where('pole_id', $request->pole_id))
            ->when($request->image_type,     fn($q) => $q->where('image_type', $request->image_type))
            ->latest()
            ->get();

        return response()->json(['data' => $images]);
    }

    public function fetchByPole(Request $request, $poleId)
    {
        $images = PoleTeardownImage::where('pole_id', $poleId)
            ->when($request->inventory_type, fn($q) => $q->where('inventory_type', $request->inventory_type))
            ->when($request->image_type,     fn($q) => $q->where('image_type', $request->image_type))
            ->latest()
            ->get();

        return response()->json(['data' => $images]);
    }

    public function fetchByReport(Request $request, $reportId)
    {
        $images = PoleTeardownImage::where('report_id', $reportId)
            ->when($request->inventory_type, fn($q) => $q->where('inventory_type', $request->inventory_type))
            ->get();

        return response()->json(['data' => $images]);
    }

    public function lock(Request $request, PoleTeardownImage $image)
    {
        $image->update([
            'locked'    => true,
            'locked_by' => $request->user()?->id,
            'locked_at' => now(),
        ]);

        return response()->json(['message' => 'Image locked', 'data' => $image]);
    }

    public function unlock(Request $request, PoleTeardownImage $image)
    {
        $image->update([
            'locked'    => false,
            'locked_by' => null,
            'locked_at' => null,
        ]);

        return response()->json(['message' => 'Image unlocked', 'data' => $image]);
    }

    private function sanitizePath($name)
    {
        return preg_replace('/[^A-Za-z0-9_\- ]/', '', $name);
    }
}
