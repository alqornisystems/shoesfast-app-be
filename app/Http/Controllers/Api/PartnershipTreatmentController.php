<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Treatment;
use App\Models\Partnership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnershipTreatmentController extends Controller
{
    /**
     * Get treatments assigned to a specific partnership (Pengerjaan Mitra)
     * Similar to technician's "my treatments" view
     */
    public function myTreatments(Request $request, $partnershipId)
    {
        // Verify partnership exists
        $partnership = Partnership::findOrFail($partnershipId);

        $query = Treatment::with([
            'service',
            'orderItem.order.customer',
            'partnership',
        ])
        ->where('partnerships_id', $partnershipId)
        ->whereHas('orderItem.order', function ($q) {
            $q->where('status', '!=', 3); // Not cancelled
        });

        // Filter by status
        $status = $request->get('status', 'in_progress');

        if ($status === 'in_progress') {
            // In progress: assigned to partnership, not completed
            $query->where('status', '!=', 2)
                  ->whereNull('done_at')
                  ->orderBy('date_end', 'ASC'); // Sort by deadline (earliest first)
        } elseif ($status === 'completed') {
            // History: completed
            $query->where('status', 2)
                  ->whereNotNull('done_at')
                  ->orderBy('done_at', 'DESC');
        }

        // Search
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('orderItem.order.customer', function ($qCustomer) use ($search) {
                    $qCustomer->where('name', 'LIKE', "%{$search}%");
                })
                ->orWhereHas('orderItem', function ($qItem) use ($search) {
                    $qItem->where('name', 'LIKE', "%{$search}%");
                })
                ->orWhereHas('orderItem.order', function ($qOrder) use ($search) {
                    $qOrder->where('code', 'LIKE', "%{$search}%");
                });
            });
        }

        $perPage = $request->get('per_page', 15);
        $treatments = $query->paginate($perPage);

        // Transform data
        $treatments->getCollection()->transform(function ($treatment) {
            $dateEnd = $treatment->date_end ?: strtotime("+{$treatment->service->estimation} day", $treatment->date_start);

            // Get previous treatment for this item (based on date_start order)
            $previousTreatment = Treatment::where('orders_items_id', $treatment->orders_items_id)
                ->where('date_start', '<', $treatment->date_start)
                ->orderBy('date_start', 'DESC')
                ->first();

            return [
                'id' => $treatment->id,
                'orders_id' => $treatment->orderItem->order->id ?? null,
                'orders_code' => $treatment->orderItem->order->code ?? null,
                'orders_items_id' => $treatment->orders_items_id,
                'orders_items_name' => $treatment->orderItem->name ?? null,
                'orders_items_photo' => $treatment->orderItem->order->customer->photo ?? null,
                'services_id' => $treatment->services_id,
                'services_name' => $treatment->service->name ?? null,
                'services_estimation' => $treatment->service->estimation ?? null,
                'customers_name' => $treatment->orderItem->order->customer->name ?? null,
                'customers_phone' => $treatment->orderItem->order->customer->phone ?? null,
                'partnerships_id' => $treatment->partnerships_id,
                'partnerships_name' => $treatment->partnership->name ?? null,
                'status' => $treatment->status,
                'date_start' => $treatment->date_start,
                'date_end' => $dateEnd,
                'progress' => $this->calculateProgress($treatment->date_start, $dateEnd),
                'price' => $treatment->price,
                'note' => $treatment->note,
                'done_at' => $treatment->done_at,
                'created_at' => $treatment->created_at,
                'previous_treatment_done_at' => $previousTreatment ? $previousTreatment->done_at : null,
                'is_first_treatment' => $previousTreatment === null,
            ];
        });

        return response()->json($treatments);
    }

    /**
     * Update treatment status by partnership
     * Partnership can start or complete their assigned treatments
     */
    public function updateStatus(Request $request, $partnershipId, $treatmentId)
    {
        // Verify partnership exists
        $partnership = Partnership::findOrFail($partnershipId);

        // Find treatment and verify it belongs to this partnership
        $treatment = Treatment::where('id', $treatmentId)
            ->where('partnerships_id', $partnershipId)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => 'required|integer|in:0,1,2',
            'note' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $updateData = [
                'status' => $validated['status'],
                'modified_by' => auth()->id() ?? null,
            ];

            if (isset($validated['note'])) {
                $updateData['note'] = $validated['note'];
            }

            // If marking as done (status = 2)
            if ($validated['status'] == 2 && !$treatment->done_at) {
                $updateData['done_at'] = time();
            }

            // If marking as in progress (status = 1) and date_start not set
            if ($validated['status'] == 1 && !$treatment->date_start) {
                $updateData['date_start'] = time();
            }

            $treatment->update($updateData);

            DB::commit();

            $statusText = match($validated['status']) {
                0 => 'Menunggu',
                1 => 'Sedang Dikerjakan',
                2 => 'Selesai',
            };

            return response()->json([
                'message' => "Status treatment berhasil diubah menjadi: {$statusText}",
                'data' => $treatment,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal update status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get treatment detail for partnership
     */
    public function show($partnershipId, $treatmentId)
    {
        // Verify partnership exists
        $partnership = Partnership::findOrFail($partnershipId);

        // Find treatment and verify it belongs to this partnership
        $treatment = Treatment::with([
            'service',
            'orderItem.order.customer',
            'partnership',
        ])
        ->where('id', $treatmentId)
        ->where('partnerships_id', $partnershipId)
        ->firstOrFail();

        $dateEnd = $treatment->date_end ?: strtotime("+{$treatment->service->estimation} day", $treatment->date_start);

        // Get previous treatment for this item
        $previousTreatment = Treatment::where('orders_items_id', $treatment->orders_items_id)
            ->where('date_start', '<', $treatment->date_start)
            ->orderBy('date_start', 'DESC')
            ->first();

        $data = [
            'id' => $treatment->id,
            'orders_id' => $treatment->orderItem->order->id ?? null,
            'orders_code' => $treatment->orderItem->order->code ?? null,
            'orders_items_id' => $treatment->orders_items_id,
            'orders_items_name' => $treatment->orderItem->name ?? null,
            'orders_items_photo' => $treatment->orderItem->photo ?? null,
            'services_id' => $treatment->services_id,
            'services_name' => $treatment->service->name ?? null,
            'services_estimation' => $treatment->service->estimation ?? null,
            'customers_name' => $treatment->orderItem->order->customer->name ?? null,
            'customers_phone' => $treatment->orderItem->order->customer->phone ?? null,
            'partnerships_id' => $treatment->partnerships_id,
            'partnerships_name' => $treatment->partnership->name ?? null,
            'status' => $treatment->status,
            'date_start' => $treatment->date_start,
            'date_end' => $dateEnd,
            'progress' => $this->calculateProgress($treatment->date_start, $dateEnd),
            'price' => $treatment->price,
            'note' => $treatment->note,
            'done_at' => $treatment->done_at,
            'created_at' => $treatment->created_at,
            'previous_treatment_done_at' => $previousTreatment ? $previousTreatment->done_at : null,
            'is_first_treatment' => $previousTreatment === null,
        ];

        return response()->json(['data' => $data]);
    }

    /**
     * Get partnership statistics
     * Shows total treatments, completed, in progress, etc.
     */
    public function statistics($partnershipId)
    {
        // Verify partnership exists
        $partnership = Partnership::findOrFail($partnershipId);

        $stats = [
            'total_treatments' => Treatment::where('partnerships_id', $partnershipId)->count(),
            'waiting' => Treatment::where('partnerships_id', $partnershipId)
                ->where('status', 0)
                ->whereNull('done_at')
                ->count(),
            'in_progress' => Treatment::where('partnerships_id', $partnershipId)
                ->where('status', 1)
                ->whereNull('done_at')
                ->count(),
            'completed' => Treatment::where('partnerships_id', $partnershipId)
                ->where('status', 2)
                ->whereNotNull('done_at')
                ->count(),
            'total_revenue' => Treatment::where('partnerships_id', $partnershipId)
                ->where('status', 2)
                ->whereNotNull('done_at')
                ->sum('price'),
        ];

        return response()->json([
            'data' => $stats,
            'partnership' => [
                'id' => $partnership->id,
                'name' => $partnership->name,
                'address' => $partnership->address,
                'phone' => $partnership->phone,
            ]
        ]);
    }

    /**
     * Calculate progress percentage
     */
    private function calculateProgress($dateStart, $dateEnd)
    {
        $now = time();

        if ($now < $dateStart) {
            return 0;
        }

        if ($now >= $dateEnd) {
            return 100;
        }

        $totalDuration = $dateEnd - $dateStart;
        $elapsed = $now - $dateStart;

        return round(($elapsed / $totalDuration) * 100);
    }
}
