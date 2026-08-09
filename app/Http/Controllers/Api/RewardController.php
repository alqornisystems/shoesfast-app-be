<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    private const TYPE_LABELS = [0 => 'Layanan', 1 => 'Barang'];

    // GET /api/rewards
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 25);
        $search = trim((string) $request->get('search', ''));

        $query = Reward::with('service')->orderBy('points_cost');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(fn (Reward $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'type' => $r->type,
            'type_label' => self::TYPE_LABELS[$r->type] ?? 'Lainnya',
            'services_id' => $r->services_id,
            // Null-safe: layanan bisa terhapus setelah hadiah dibuat.
            'service_name' => $r->service?->name,
            'points_cost' => $r->points_cost,
            'photo' => $r->photo,
            'is_active' => $r->is_active,
        ]);

        return response()->json($paginator);
    }

    // POST /api/rewards
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $validated['created_by'] = auth()->id();

        $reward = Reward::create($validated);

        return response()->json(['data' => $reward], 201);
    }

    // PUT /api/rewards/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $reward = Reward::find($id);

        if (! $reward) {
            return response()->json(['message' => 'Hadiah tidak ditemukan'], 404);
        }

        $validated = $this->validated($request);
        $validated['modified_by'] = auth()->id();

        $reward->update($validated);

        return response()->json(['data' => $reward]);
    }

    // DELETE /api/rewards/{id}
    public function destroy(int $id): JsonResponse
    {
        $reward = Reward::find($id);

        if (! $reward) {
            return response()->json(['message' => 'Hadiah tidak ditemukan'], 404);
        }

        // Hapus lunak: penukaran lama menunjuk baris ini dan riwayat pelanggan
        // akan kehilangan nama hadiahnya kalau barisnya benar-benar hilang.
        $reward->delete();

        return response()->json(['message' => 'Hadiah dihapus']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'integer', 'in:0,1'],
            'services_id' => ['nullable', 'integer'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'photo' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
