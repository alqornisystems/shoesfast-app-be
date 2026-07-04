<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceHpp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceHppController extends Controller
{
    // GET /api/services/{serviceId}/hpp
    public function index(int $serviceId): JsonResponse
    {
        $hppItems = ServiceHpp::forService($serviceId)
            ->orderBy('status')
            ->orderBy('name')
            ->get()
            ->groupBy('status')
            ->map(function ($items) {
                return $items->map(function ($item) {
                    return [
                        'id'                 => $item->id,
                        'services_id'        => $item->services_id,
                        'name'               => $item->name,
                        'unit'               => $item->unit,
                        'total_stock'        => $item->total_stock,
                        'usage_per_service'  => $item->usage_per_service,
                        'total_cost'         => $item->total_cost,
                        'cost_per_usage'     => $item->cost_per_usage,
                        'status'             => $item->status,
                    ];
                })->values();
            });

        // Ensure all categories exist
        $response = [
            'direct_material'   => $hppItems->get('direct', collect())->all(),
            'direct_labor'      => $hppItems->get('labor', collect())->all(),
            'indirect_material' => $hppItems->get('indirect', collect())->all(),
        ];

        // Calculate totals
        $directMaterialTotal = collect($response['direct_material'])->sum('cost_per_usage');
        $directLaborTotal = collect($response['direct_labor'])->sum('cost_per_usage');
        $indirectMaterialTotal = collect($response['indirect_material'])->sum('cost_per_usage');
        $totalHpp = $directMaterialTotal + $directLaborTotal + $indirectMaterialTotal;

        return response()->json([
            'data' => $response,
            'summary' => [
                'direct_material_total'   => $directMaterialTotal,
                'direct_labor_total'      => $directLaborTotal,
                'indirect_material_total' => $indirectMaterialTotal,
                'total_hpp'               => $totalHpp,
            ],
        ]);
    }

    // POST /api/services/{serviceId}/hpp
    public function store(Request $request, int $serviceId): JsonResponse
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:100'],
            'unit'               => ['required', 'string', 'max:10'],
            'total_stock'        => ['required', 'integer', 'min:1'],
            'usage_per_service'  => ['required', 'integer', 'min:1'],
            'total_cost'         => ['required', 'integer', 'min:0'],
            'cost_per_usage'     => ['required', 'integer', 'min:0'],
            'status'             => ['required', 'in:direct,indirect,labor'],
        ]);

        $hpp = ServiceHpp::create([
            'services_id'       => $serviceId,
            'name'              => $validated['name'],
            'unit'              => $validated['unit'],
            'total_stock'       => $validated['total_stock'],
            'usage_per_service' => $validated['usage_per_service'],
            'total_cost'        => $validated['total_cost'],
            'cost_per_usage'    => $validated['cost_per_usage'],
            'status'            => $validated['status'],
            'created_by'        => auth()->id() ?? 1,
            'modified_by'       => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'HPP item berhasil ditambahkan.',
            'data'    => $hpp,
        ], 201);
    }

    // PUT /api/services/{serviceId}/hpp/{id}
    public function update(Request $request, int $serviceId, int $id): JsonResponse
    {
        $hpp = ServiceHpp::forService($serviceId)->findOrFail($id);

        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:100'],
            'unit'               => ['required', 'string', 'max:10'],
            'total_stock'        => ['required', 'integer', 'min:1'],
            'usage_per_service'  => ['required', 'integer', 'min:1'],
            'total_cost'         => ['required', 'integer', 'min:0'],
            'cost_per_usage'     => ['required', 'integer', 'min:0'],
            'status'             => ['required', 'in:direct,indirect,labor'],
        ]);

        $hpp->update([
            'name'              => $validated['name'],
            'unit'              => $validated['unit'],
            'total_stock'       => $validated['total_stock'],
            'usage_per_service' => $validated['usage_per_service'],
            'total_cost'        => $validated['total_cost'],
            'cost_per_usage'    => $validated['cost_per_usage'],
            'status'            => $validated['status'],
            'modified_by'       => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'HPP item berhasil diperbarui.',
            'data'    => $hpp->fresh(),
        ]);
    }

    // DELETE /api/services/{serviceId}/hpp/{id}
    public function destroy(int $serviceId, int $id): JsonResponse
    {
        $hpp = ServiceHpp::forService($serviceId)->findOrFail($id);
        $hpp->delete();

        return response()->json(['message' => 'HPP item berhasil dihapus.']);
    }

    // POST /api/services/{serviceId}/hpp/batch
    // Batch save all HPP items for a service
    public function batchSave(Request $request, int $serviceId): JsonResponse
    {
        $validated = $request->validate([
            'direct_material'   => ['nullable', 'array'],
            'direct_material.*' => ['array'],
            'direct_labor'      => ['nullable', 'array'],
            'direct_labor.*'    => ['array'],
            'indirect_material' => ['nullable', 'array'],
            'indirect_material.*' => ['array'],
        ]);

        // Delete existing HPP items for this service
        ServiceHpp::forService($serviceId)->delete();

        $savedItems = [];
        $totalHpp = 0;
        $statusMap = [
            'direct_material'   => 'direct',
            'direct_labor'      => 'labor',
            'indirect_material' => 'indirect',
        ];

        foreach ($statusMap as $key => $status) {
            if (isset($validated[$key]) && is_array($validated[$key])) {
                foreach ($validated[$key] as $item) {
                    if (empty($item['name'])) {
                        continue; // Skip empty items
                    }

                    $costPerUsage = $item['cost_per_usage'] ?? 0;
                    $totalHpp += $costPerUsage;

                    $savedItems[] = ServiceHpp::create([
                        'services_id'       => $serviceId,
                        'name'              => $item['name'] ?? '',
                        'unit'              => $item['unit'] ?? '',
                        'total_stock'       => $item['total_stock'] ?? 0,
                        'usage_per_service' => $item['usage_per_service'] ?? 0,
                        'total_cost'        => $item['total_cost'] ?? 0,
                        'cost_per_usage'    => $costPerUsage,
                        'status'            => $status,
                        'created_by'        => auth()->id() ?? 1,
                        'modified_by'       => auth()->id() ?? 1,
                    ]);
                }
            }
        }

        // Update hpp field in services table with total HPP
        $service = Service::findOrFail($serviceId);
        $service->update([
            'hpp' => $totalHpp,
            'modified_by' => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'HPP items berhasil disimpan.',
            'data'    => $savedItems,
            'total_hpp' => $totalHpp,
        ]);
    }
}
