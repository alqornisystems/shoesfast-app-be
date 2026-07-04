<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Treatment;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TreatmentController extends Controller
{
    protected FcmService $fcm;

    public function __construct(FcmService $fcm)
    {
        $this->fcm = $fcm;
    }

    /**
     * Display a listing of treatments (waiting list)
     */
    public function index(Request $request)
    {
        $query = Treatment::with([
            'service',
            'orderItem.order.customer',
            'user',
        ])
            ->whereHas('orderItem.order', function ($q) {
                $q->where('status', '!=', 3); // Not cancelled
            });

        // Filter by page type (accept both 'page' and 'page_type' parameters)
        $page = $request->input('page_type') ?? $request->input('page', 'waiting_list');

        if ($page === 'waiting_list') {
            // Waiting list: no user assigned, no partnership, order still in process
            // Only show FIRST pending treatment per ORDER (sequential logic per order)
            // Treatment muncul berdasarkan date_start ASC + id ASC (tiebreaker jika date_start sama)
            $query->whereNull('users_id')
                ->whereNull('partnerships_id')
                ->whereNull('done_at') // Hanya treatment yang belum selesai
                ->whereHas('orderItem.order', function ($q) {
                    $q->where('status', '!=', 2); // Not done (still pending or in process)
                })
                ->whereNotExists(function ($subquery) {
                    $subquery->select(DB::raw(1))
                        ->from('treatments as t2')
                        ->join('orders_items as oi2', function ($join) {
                            $join->on('t2.orders_items_id', '=', 'oi2.id')
                                ->where('oi2.is_deleted', '=', 0);
                        })
                        ->join('orders_items as oi_current', function ($join) {
                            $join->on('treatments.orders_items_id', '=', 'oi_current.id')
                                ->where('oi_current.is_deleted', '=', 0);
                        })
                        ->whereColumn('oi2.orders_id', '=', 'oi_current.orders_id')
                        ->whereNull('t2.done_at')
                        ->where('t2.is_deleted', '=', 0)
                        ->where(function ($q) {
                            // Treatment lain yang lebih dulu: date_start lebih kecil, ATAU same date tapi ID lebih kecil
                            $q->whereColumn('t2.date_start', '<', 'treatments.date_start')
                                ->orWhere(function ($q2) {
                                    $q2->whereColumn('t2.date_start', '=', 'treatments.date_start')
                                        ->whereColumn('t2.id', '<', 'treatments.id');
                                });
                        });
                })
                ->orderBy('created_at', 'ASC'); // Oldest first
        } elseif ($page === 'pengerjaan') {
            // In progress: assigned to user, not completed
            $query->whereNotNull('users_id')
                ->where('status', '!=', 2)
                ->orderBy('date_end', 'ASC'); // Sort by deadline (earliest first)
        } elseif ($page === 'pengerjaan-vendor') {
            // Vendor work: assigned to partnership, not completed
            $query->whereNotNull('partnerships_id')
                ->where('status', '!=', 2)
                ->orderBy('date_start', 'DESC');
        } elseif ($page === 'history') {
            // History: completed or cancelled
            $query->where('status', '>=', 2)
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

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        $perPage = $request->input('per_page', 15);
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
                'orders_items_photo' => $treatment->orderItem->photo ?? null,
                'services_id' => $treatment->services_id,
                'services_name' => $treatment->service->name ?? null,
                'services_estimation' => $treatment->service->estimation ?? null,
                'customers_name' => $treatment->orderItem->order->customer->name ?? null,
                'customers_phone' => $treatment->orderItem->order->customer->phone ?? null,
                'users_id' => $treatment->users_id,
                'users_name' => $treatment->user->name ?? null,
                'partnerships_id' => $treatment->partnerships_id,
                'status' => $treatment->status,
                'date_start' => $treatment->date_start,
                'date_end' => $dateEnd,
                'progress' => $this->calculateProgress($treatment->date_start, $dateEnd),
                'price' => $treatment->price,
                'note' => $treatment->note,
                'is_partnerships' => $treatment->is_partnerships,
                'done_at' => $treatment->done_at,
                'created_at' => $treatment->created_at,
                'previous_treatment_done_at' => $previousTreatment ? $previousTreatment->done_at : null,
                'is_first_treatment' => $previousTreatment === null,
            ];
        });

        return response()->json($treatments);
    }

    /**
     * Assign treatments to user (teknisi) or partnership (mitra)
     */
    public function assignToUser(Request $request)
    {
        $validated = $request->validate([
            'users_id' => 'nullable|exists:users,id',
            'partnerships_id' => 'nullable|exists:partnerships,id',
            'treatment_ids' => 'required|array',
            'treatment_ids.*' => 'exists:treatments,id',
            'date_start' => 'nullable|integer|min:0',
        ]);

        // Validate at least one of users_id or partnerships_id must be provided
        if (empty($validated['users_id']) && empty($validated['partnerships_id'])) {
            return response()->json([
                'message' => 'users_id atau partnerships_id harus diisi',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $treatments = Treatment::whereIn('id', $validated['treatment_ids'])->get();

            foreach ($treatments as $treatment) {
                $updateData = [
                    'status' => 0, // Keep status 0 (assigned but not started yet)
                    'modified_by' => auth()->id(),
                ];

                if (! empty($validated['users_id'])) {
                    $updateData['users_id'] = $validated['users_id'];
                    $updateData['partnerships_id'] = null;
                    $updateData['is_partnerships'] = 0;
                } else {
                    $updateData['partnerships_id'] = $validated['partnerships_id'];
                    $updateData['users_id'] = null;
                    $updateData['is_partnerships'] = 1;
                }

                // Update date_start if provided
                if (! empty($validated['date_start'])) {
                    $updateData['date_start'] = $validated['date_start'];
                }

                // Calculate date_end if not set
                if (! $treatment->date_end) {
                    $service = $treatment->service;
                    if ($service) {
                        $dateStart = ! empty($validated['date_start']) ? $validated['date_start'] : $treatment->date_start;
                        $updateData['date_end'] = strtotime("+{$service->estimation} day", $dateStart);
                    }
                }

                $treatment->update($updateData);
            }

            DB::commit();

            // Send FCM notification to teknisi if assigned to user
            if (! empty($validated['users_id'])) {
                $user = User::find($validated['users_id']);
                $treatmentCount = count($validated['treatment_ids']);

                if ($user) {
                    $title = '⚙️ Pengerjaan Baru Untukmu '.$user->name;
                    $body = "Kamu mendapatkan {$treatmentCount} pengerjaan baru nih, Jangan lupa dicek...";

                    $this->fcm->sendUserNotification($user->id, $title, $body, 'treatment');
                }
            }

            $message = ! empty($validated['users_id'])
                ? 'Treatment berhasil di-assign ke teknisi'
                : 'Treatment berhasil di-assign ke mitra';

            return response()->json([
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal assign treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update treatment status
     */
    public function updateStatus(Request $request, $id)
    {
        $treatment = Treatment::with(['user', 'orderItem.order'])->findOrFail($id);
        $oldStatus = $treatment->status;

        $validated = $request->validate([
            'status' => 'required|integer|in:0,1,2',
            'note' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $updateData = [
                'status' => $validated['status'],
                'modified_by' => auth()->id(),
            ];

            if (isset($validated['note'])) {
                $updateData['note'] = $validated['note'];
            }

            // If marking as done
            if ($validated['status'] == 2 && ! $treatment->done_at) {
                $updateData['done_at'] = time();
            }

            $treatment->update($updateData);

            DB::commit();

            // Send FCM notifications based on status change
            $orderCode = $treatment->orderItem->order->code ?? 'N/A';

            // Jika pengerjaan dikembalikan ke teknisi (status kembali ke 0)
            if ($validated['status'] == 0 && $oldStatus != 0 && $treatment->user) {
                $title = '↩️ Pengerjaan Dikembalikan';
                $body = "Halo {$treatment->user->name}, Pengerjaan untuk order {$orderCode} dikembalikan ke kamu. Silakan dicek...";

                $this->fcm->sendUserNotification($treatment->user->id, $title, $body, 'treatment_returned');
            }

            // Jika pengerjaan selesai (status = 2), kirim notif ke QA (Teknisi Leader)
            if ($validated['status'] == 2 && $oldStatus != 2) {
                // Get QA user (user dengan role "Teknisi Leader")
                $qaUser = User::whereHas('role', function ($q) {
                    $q->where('name', 'Teknisi Leader');
                })->first();

                if ($qaUser) {
                    $title = '✅ Ada Barang Baru Yang Bisa Dicek';
                    $body = "Halo, Ada barang baru dari order {$orderCode} yang sudah selesai dikerjakan. Segera dicek ya...";

                    $this->fcm->sendUserNotification($qaUser->id, $title, $body, 'treatment_completed');
                }
            }

            return response()->json([
                'message' => 'Status treatment berhasil diupdate',
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
     * Get available technicians (users with role Teknisi)
     */
    public function getAvailableTechnicians(Request $request)
    {
        $users = User::where('is_deleted', 0)
            ->whereHas('role', function ($q) {
                $q->where('name', 'Teknisi');
            })
            ->orderBy('name', 'ASC')
            ->get(['id', 'name', 'phone', 'email']);

        return response()->json($users);
    }

    /**
     * Update treatment (teknisi and date_end)
     */
    public function update(Request $request, $id)
    {
        $treatment = Treatment::findOrFail($id);

        $validated = $request->validate([
            'users_id' => 'nullable|exists:users,id',
            'date_end' => 'nullable|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            $updateData = [
                'modified_by' => auth()->id(),
            ];

            if (isset($validated['users_id'])) {
                $updateData['users_id'] = $validated['users_id'];
            }

            if (isset($validated['date_end'])) {
                $updateData['date_end'] = $validated['date_end'];
            }

            $treatment->update($updateData);

            DB::commit();

            return response()->json([
                'message' => 'Treatment berhasil diupdate',
                'data' => $treatment,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal update treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Force complete treatments (for cancelled orders)
     */
    public function forceComplete(Request $request)
    {
        $validated = $request->validate([
            'treatment_ids' => 'required|array',
            'treatment_ids.*' => 'exists:treatments,id',
        ]);

        DB::beginTransaction();
        try {
            $treatments = Treatment::whereIn('id', $validated['treatment_ids'])->get();

            foreach ($treatments as $treatment) {
                $treatment->update([
                    'status' => 2, // Force to Done
                    'done_at' => time(),
                    'note' => ($treatment->note ? $treatment->note."\n\n" : '').'[Diselesaikan paksa] - '.date('Y-m-d H:i:s'),
                    'modified_by' => auth()->id(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Treatment berhasil diselesaikan paksa',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal menyelesaikan treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
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
