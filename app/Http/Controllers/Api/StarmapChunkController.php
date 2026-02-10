<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Planet;
use App\Models\Star;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StarmapChunkController extends Controller
{
    /**
     * Default chunk size (25x25 grid).
     */
    const CHUNK_SIZE = 25;

    /**
     * Cache duration in seconds (5 minutes).
     */
    const CACHE_TTL = 300;

    /**
     * Max allowed chunk coordinate (prevents absurd queries).
     * 10000 * 25 = 250,000 max coordinate value.
     */
    const MAX_CHUNK_COORD = 10000;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('verified');
        $this->middleware('player');
    }

    /**
     * Get stars and planets in a specific chunk.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     *
     * Usage: GET /api/starmap/chunk?x=0&y=0&size=25
     */
    public function chunk(Request $request)
    {
        $request->validate([
            'x' => 'required|integer|min:0|max:' . self::MAX_CHUNK_COORD,
            'y' => 'required|integer|min:0|max:' . self::MAX_CHUNK_COORD,
            'size' => 'nullable|integer|min:5|max:100',
        ]);

        $chunkX = (int) $request->x;
        $chunkY = (int) $request->y;
        $size = (int) ($request->size ?? self::CHUNK_SIZE);

        $data = $this->loadChunk($chunkX, $chunkY, $size);

        $xStart = $chunkX * $size;
        $yStart = $chunkY * $size;

        return response()->json([
            'chunk' => ['x' => $chunkX, 'y' => $chunkY, 'size' => $size],
            'bounds' => [
                'x_start' => $xStart,
                'y_start' => $yStart,
                'x_end' => $xStart + $size,
                'y_end' => $yStart + $size,
            ],
            'data' => $data,
            'counts' => [
                'stars' => count($data['stars']),
                'planets' => count($data['planets']),
            ],
        ]);
    }

    /**
     * Get multiple chunks at once (batch loading).
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     *
     * Usage: POST /api/starmap/chunks
     * Body: { "chunks": [{"x": 0, "y": 0}, {"x": 1, "y": 0}], "size": 25 }
     */
    public function chunks(Request $request)
    {
        $request->validate([
            'chunks' => 'required|array|max:16',
            'chunks.*.x' => 'required|integer|min:0|max:' . self::MAX_CHUNK_COORD,
            'chunks.*.y' => 'required|integer|min:0|max:' . self::MAX_CHUNK_COORD,
            'size' => 'nullable|integer|min:5|max:100',
        ]);

        $size = (int) ($request->size ?? self::CHUNK_SIZE);
        $results = [];

        foreach ($request->chunks as $chunk) {
            $chunkX = (int) $chunk['x'];
            $chunkY = (int) $chunk['y'];

            $results["{$chunkX},{$chunkY}"] = $this->loadChunk($chunkX, $chunkY, $size);
        }

        return response()->json([
            'size' => $size,
            'chunks' => $results,
        ]);
    }

    /**
     * Get map metadata (total size, chunk count).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function meta()
    {
        $data = Cache::remember('starmap_meta', 3600, function () {
            $maxX = max(Star::max('x') ?? 0, Planet::max('x') ?? 0);
            $maxY = max(Star::max('y') ?? 0, Planet::max('y') ?? 0);

            return [
                'max_x' => $maxX,
                'max_y' => $maxY,
                'total_stars' => Star::count(),
                'total_planets' => Planet::count(),
                'chunk_size' => self::CHUNK_SIZE,
                'chunks_x' => $maxX > 0 ? (int) ceil($maxX / self::CHUNK_SIZE) : 0,
                'chunks_y' => $maxY > 0 ? (int) ceil($maxY / self::CHUNK_SIZE) : 0,
            ];
        });

        return response()->json($data);
    }

    /**
     * Load a single chunk's data (stars + planets) with caching.
     *
     * @param  int  $chunkX
     * @param  int  $chunkY
     * @param  int  $size
     * @return array
     */
    protected function loadChunk(int $chunkX, int $chunkY, int $size): array
    {
        $cacheKey = "starmap_chunk_{$chunkX}_{$chunkY}_{$size}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($chunkX, $chunkY, $size) {
            $xStart = $chunkX * $size;
            $yStart = $chunkY * $size;
            $xEnd = $xStart + $size;
            $yEnd = $yStart + $size;

            $stars = Star::where('x', '>=', $xStart)
                ->where('x', '<', $xEnd)
                ->where('y', '>=', $yStart)
                ->where('y', '<', $yEnd)
                ->select('id', 'name', 'x', 'y')
                ->get();

            $planets = Planet::where('x', '>=', $xStart)
                ->where('x', '<', $xEnd)
                ->where('y', '>=', $yStart)
                ->where('y', '<', $yEnd)
                ->select('id', 'name', 'custom_name', 'x', 'y', 'size', 'resource_id', 'user_id')
                ->with('resource:id,name')
                ->get();

            return [
                'stars' => $stars->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'x' => $s->x,
                    'y' => $s->y,
                ])->values()->all(),
                'planets' => $planets->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->custom_name ?? $p->name,
                    'x' => $p->x,
                    'y' => $p->y,
                    'size' => $p->size,
                    'resource' => $p->resource?->name,
                    'occupied' => ! is_null($p->user_id),
                ])->values()->all(),
            ];
        });
    }
}
