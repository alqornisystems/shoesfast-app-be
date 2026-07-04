<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Send;
use App\Models\User;
use App\Services\FcmService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SendController extends Controller
{
    protected WhatsAppService $whatsapp;

    protected FcmService $fcm;

    public function __construct(WhatsAppService $whatsapp, FcmService $fcm)
    {
        $this->whatsapp = $whatsapp;
        $this->fcm = $fcm;
    }

    /**
     * Display a listing of sends
     */
    public function index(Request $request)
    {
        $query = Send::with([
            'user' => function ($query) {
                $query->withoutGlobalScopes(); // Load user without branch/deleted scope
            },
            'order.customer',
            'orderItem',
            'project',
        ])
            ->whereNotNull('users_id') // Only show sends with courier assigned
            ->orderBy('id', 'DESC');

        // Filter by type (0=pickup, 1=delivery)
        if ($request->has('type') && $request->type !== '') {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Filter by date
        if ($request->has('date') && $request->date !== '') {
            $date = strtotime($request->date);
            $query->where('date', '>=', $date)
                ->where('date', '<', $date + 86400); // +1 day
        }

        // Search by order code or customer name
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('order', function ($qOrder) use ($search) {
                    $qOrder->where('code', 'LIKE', "%{$search}%")
                        ->orWhereHas('customer', function ($qCustomer) use ($search) {
                            $qCustomer->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('phone', 'LIKE', "%{$search}%");
                        });
                });
            });
        }

        $perPage = $request->get('per_page', 15);
        $sends = $query->paginate($perPage);

        // Transform data
        $sends->getCollection()->transform(function ($send) {
            return [
                'id' => $send->id,
                'date' => $send->date,
                'status' => $send->status,
                'type' => $send->type,
                'user' => [
                    'id' => $send->user->id ?? null,
                    'name' => $send->user->name ?? null,
                    'phone' => $send->user->phone ?? null,
                ],
                'order' => [
                    'id' => $send->order->id ?? null,
                    'code' => $send->order->code ?? null,
                    'customer_name' => $send->order->customer->name ?? null,
                    'customer_phone' => $send->order->customer->phone ?? null,
                    'customer_address' => $send->order->customer->address ?? null,
                ],
                'order_item' => $send->type == 1 ? [
                    'id' => $send->orderItem->id ?? null,
                    'name' => $send->orderItem->name ?? null,
                ] : null,
                'project_name' => $send->project->name ?? null,
                'created_at' => $send->created_at,
            ];
        });

        return response()->json($sends);
    }

    /**
     * Display the specified send
     */
    public function show($id)
    {
        $send = Send::with(['user', 'order.customer', 'orderItem', 'project'])
            ->findOrFail($id);

        return response()->json([
            'id' => $send->id,
            'date' => $send->date,
            'status' => $send->status,
            'type' => $send->type,
            'user' => $send->user ? [
                'id' => $send->user->id,
                'name' => $send->user->name,
                'phone' => $send->user->phone ?? null,
                'email' => $send->user->email ?? null,
            ] : null,
            'order' => $send->order ? [
                'id' => $send->order->id,
                'code' => $send->order->code,
                'customer_id' => $send->order->customer->id ?? null,
                'customer_name' => $send->order->customer->name ?? null,
                'customer_phone' => $send->order->customer->phone ?? null,
                'customer_email' => $send->order->customer->email ?? null,
                'customer_address' => $send->order->customer->address ?? null,
                'customer_maps' => $send->order->customer->maps ?? null,
            ] : null,
            'order_item' => $send->type == 1 && $send->orderItem ? [
                'id' => $send->orderItem->id,
                'name' => $send->orderItem->name,
            ] : null,
            'project_name' => $send->project ? $send->project->name : null,
            'created_at' => $send->created_at,
        ]);
    }

    /**
     * Store a newly created send
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'users_id' => 'required|exists:users,id',
            'orders_id' => 'nullable|exists:orders,id',
            'orders_items_id' => 'nullable|exists:orders_items,id',
            'date' => 'required|date',
            'type' => 'required|integer|in:0,1',
            'status' => 'nullable|integer|in:0,1',
        ]);

        // Validate based on type
        if ($validated['type'] == 0 && ! $validated['orders_id']) {
            return response()->json([
                'message' => 'orders_id is required for pickup',
            ], 422);
        }

        if ($validated['type'] == 1 && ! $validated['orders_items_id']) {
            return response()->json([
                'message' => 'orders_items_id is required for delivery',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Get orders_id from order_item if not provided (for delivery)
            $ordersId = $validated['orders_id'] ?? null;

            if ($validated['type'] == 1 && $validated['orders_items_id']) {
                $orderItem = OrderItem::find($validated['orders_items_id']);
                if (! $orderItem) {
                    return response()->json([
                        'message' => 'Order item not found',
                    ], 404);
                }
                $ordersId = $orderItem->orders_id;
            }

            if (! $ordersId) {
                return response()->json([
                    'message' => 'orders_id is required',
                ], 422);
            }

            $send = Send::create([
                'users_id' => $validated['users_id'],
                'orders_id' => $ordersId,
                'orders_items_id' => $validated['orders_items_id'] ?? null,
                'date' => strtotime($validated['date']),
                'type' => $validated['type'],
                'status' => $validated['status'] ?? 0,
                'created_by' => auth()->id(),
            ]);

            // Update order/item status
            if ($validated['type'] == 0) { // Pickup
                Order::where('id', $ordersId)
                    ->update(['status' => 0]); // Keep as pending until pickup completed
            } elseif ($validated['type'] == 1 && $validated['orders_items_id']) { // Delivery
                OrderItem::where('id', $validated['orders_items_id'])
                    ->update(['status' => 3]); // Set to delivery status
            }

            DB::commit();

            // Load relationships for response
            $send->load(['user', 'order.customer', 'orderItem']);

            // Send notifications
            $customer = $send->order->customer ?? null;
            $courier = $send->user ?? null;

            if ($customer && $customer->phone && $courier) {
                $typeLabel = $send->type == 0 ? 'pickup' : 'pengiriman';
                $courierPhone = $courier->phone ? "\nNomor Kurir: {$courier->phone}" : '';

                // WhatsApp to customer
                $message = "Halo {$customer->name},\n\n"
                    ."Kurir kami *{$courier->name}* sedang dalam perjalanan untuk {$typeLabel} pesanan Anda.\n\n"
                    ."Order: *{$send->order->code}*{$courierPhone}\n\n"
                    ."Anda bisa pantau lokasi kurir secara real-time di:\n"
                    ."https://customer.shoesfast.id\n\n"
                    ."Login menggunakan nomor WhatsApp Anda untuk melihat tracking kurir.\n\n"
                    .'Terima kasih! 🙏';

                $this->whatsapp->sendMessage($customer->phone, $message);

                // FCM to customer
                $fcmMessage = "Kurir {$courier->name} sedang dalam perjalanan untuk {$typeLabel} pesanan {$send->order->code}";
                $this->fcm->sendDeliveryNotification($customer->phone, $send->order->code, $courier->name);
            }

            // FCM notification to courier (teknisi/kurir)
            if ($courier) {
                $typeIcon = $send->type == 0 ? '📦' : '🚚';
                $typeLabel = $send->type == 0 ? 'Pickup' : 'Delivery';
                $title = "{$typeIcon} {$typeLabel} Baru Untukmu {$courier->name}";
                $body = "Kamu mendapatkan tugas {$typeLabel} untuk order {$send->order->code}. Jangan lupa dicek...";

                $this->fcm->sendUserNotification($courier->id, $title, $body, 'delivery');
            }

            return response()->json([
                'message' => 'Pengiriman berhasil dibuat',
                'data' => $send,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal membuat pengiriman',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified send
     */
    public function update(Request $request, $id)
    {
        $send = Send::findOrFail($id);

        $validated = $request->validate([
            'users_id' => 'required|exists:users,id', // Make courier required on update
            'date' => 'sometimes|date',
            'status' => 'sometimes|integer|in:0,1',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['date'])) {
                $validated['date'] = strtotime($validated['date']);
            }

            $validated['modified_by'] = auth()->id();
            $send->update($validated);

            // Update order/item status based on send status
            if (isset($validated['status'])) {
                if ($send->type == 0) { // Pickup
                    if ($validated['status'] == 1) {
                        Order::where('id', $send->orders_id)
                            ->update(['status' => 1]); // Set to process
                    } else {
                        Order::where('id', $send->orders_id)
                            ->update(['status' => 0]); // Set to pending
                    }
                } elseif ($send->type == 1 && $send->orders_items_id) { // Delivery
                    if ($validated['status'] == 1) {
                        OrderItem::where('id', $send->orders_items_id)
                            ->update(['status' => 4]); // Set to done
                    } else {
                        OrderItem::where('id', $send->orders_items_id)
                            ->update(['status' => 3]); // Set to delivery
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Pengiriman berhasil diupdate',
                'data' => $send->load(['user', 'order.customer', 'orderItem']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal mengupdate pengiriman',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Soft delete the specified send
     */
    public function destroy($id)
    {
        $send = Send::findOrFail($id);

        DB::beginTransaction();
        try {
            $send->update([
                'is_deleted' => 1,
                'modified_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Pengiriman berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal menghapus pengiriman',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/sends/pickup-waiting-list
     * Get waiting list for pickup (orders that need to be picked up)
     */
    public function pickupWaitingList(Request $request)
    {
        $orders = Order::with(['customer', 'project'])
            ->where('status', 0) // Pending orders only
            ->whereDoesntHave('sends', function ($q) {
                $q->where('type', 0); // No pickup created yet
            })
            ->orderBy('date', 'DESC')
            ->get();

        $orders->transform(function ($order) {
            return [
                'id' => $order->id,
                'code' => $order->code,
                'date' => $order->date,
                'customer_name' => $order->customer->name ?? null,
                'customer_phone' => $order->customer->phone ?? null,
                'customer_address' => $order->customer->address ?? null,
                'customer_maps' => $order->customer->maps ?? null,
                'project_name' => $order->project->name ?? null,
                'total_price' => $order->total_price,
                'created_at' => $order->created_at,
            ];
        });

        return response()->json([
            'data' => $orders,
        ]);
    }

    /**
     * Get available orders for pickup (legacy endpoint for create form)
     */
    public function getAvailablePickupOrders(Request $request)
    {
        $orders = Order::with(['customer'])
            ->where('status', 0) // Pending orders only
            ->whereDoesntHave('sends', function ($q) {
                $q->where('type', 0); // No pickup created yet
            })
            ->orderBy('date', 'DESC')
            ->limit(50)
            ->get();

        $orders->transform(function ($order) {
            return [
                'id' => $order->id,
                'code' => $order->code,
                'customer_name' => $order->customer->name,
                'customer_phone' => $order->customer->phone,
                'customer_address' => $order->customer->address,
                'customer_maps' => $order->customer->maps,
            ];
        });

        return response()->json($orders);
    }

    /**
     * GET /api/sends/delivery-waiting-list
     * Get waiting list for delivery (items that are ready to be delivered)
     */
    public function deliveryWaitingList(Request $request)
    {
        $items = OrderItem::with(['order.customer', 'order.project'])
            ->where('status', 2) // Completed items only
            ->whereDoesntHave('sends', function ($q) {
                $q->where('type', 1); // No delivery created yet
            })
            ->orderBy('id', 'DESC')
            ->get();

        $items->transform(function ($item) {
            // Convert photo path to full URL
            $photoUrl = null;
            if ($item->photo) {
                if (filter_var($item->photo, FILTER_VALIDATE_URL)) {
                    $photoUrl = $item->photo;
                } else {
                    $photoUrl = asset('storage/'.$item->photo);
                }
            }

            return [
                'id' => $item->id,
                'orders_id' => $item->orders_id,
                'order_code' => $item->order->code ?? null,
                'name' => $item->name,
                'price' => $item->price,
                'discount' => $item->discount,
                'photo' => $photoUrl,
                'customer_name' => $item->order->customer->name ?? null,
                'customer_phone' => $item->order->customer->phone ?? null,
                'customer_address' => $item->order->customer->address ?? null,
                'customer_maps' => $item->order->customer->maps ?? null,
                'project_name' => $item->order->project->name ?? null,
                'created_at' => $item->created_at,
            ];
        });

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * Get available items for delivery (legacy endpoint for create form)
     */
    public function getAvailableDeliveryItems(Request $request)
    {
        $items = OrderItem::with(['order.customer'])
            ->where('status', 2) // Completed items only
            ->whereDoesntHave('sends', function ($q) {
                $q->where('type', 1); // No delivery created yet
            })
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get();

        $items->transform(function ($item) {
            // Convert photo path to full URL
            $photoUrl = null;
            if ($item->photo) {
                if (filter_var($item->photo, FILTER_VALIDATE_URL)) {
                    $photoUrl = $item->photo;
                } else {
                    $photoUrl = asset('storage/'.$item->photo);
                }
            }

            return [
                'id' => $item->id,
                'orders_id' => $item->orders_id,
                'name' => $item->name,
                'order_code' => $item->order->code,
                'customer_name' => $item->order->customer->name,
                'customer_phone' => $item->order->customer->phone,
                'customer_address' => $item->order->customer->address,
                'customer_maps' => $item->order->customer->maps,
                'photo' => $photoUrl,
            ];
        });

        return response()->json($items);
    }

    /**
     * GET /api/sends/in-progress
     * Get sends that are in progress (status = 0)
     */
    public function inProgress(Request $request)
    {
        $request->validate([
            'type' => ['nullable', 'integer', 'in:0,1'], // 0=pickup, 1=delivery
        ]);

        $query = Send::with([
            'user' => function ($query) {
                $query->withoutGlobalScopes();
            },
            'order.customer',
            'orderItem',
            'project',
        ])
            ->where('status', 0) // In progress only
            ->orderBy('date', 'DESC');

        // Filter by type if provided
        if ($request->has('type') && $request->type !== null) {
            $query->where('type', $request->type);
        }

        $sends = $query->get();

        $sends->transform(function ($send) {
            return [
                'id' => $send->id,
                'date' => $send->date,
                'type' => $send->type,
                'type_label' => $send->type == 0 ? 'Pickup' : 'Delivery',
                'status' => $send->status,
                'courier_name' => $send->user->name ?? null,
                'courier_phone' => $send->user->phone ?? null,
                'order_code' => $send->order->code ?? null,
                'customer_name' => $send->order->customer->name ?? null,
                'customer_phone' => $send->order->customer->phone ?? null,
                'customer_address' => $send->order->customer->address ?? null,
                'customer_maps' => $send->order->customer->maps ?? null,
                'item_name' => $send->type == 1 ? ($send->orderItem->name ?? null) : null,
                'project_name' => $send->project->name ?? null,
                'created_at' => $send->created_at,
            ];
        });

        return response()->json([
            'data' => $sends,
        ]);
    }

    /**
     * GET /api/sends/history
     * Get completed sends (status = 1)
     */
    public function history(Request $request)
    {
        $request->validate([
            'type' => ['nullable', 'integer', 'in:0,1'], // 0=pickup, 1=delivery
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $query = Send::with([
            'user' => function ($query) {
                $query->withoutGlobalScopes();
            },
            'order.customer',
            'orderItem',
            'project',
        ])
            ->where('status', 1) // Completed only
            ->orderBy('date', 'DESC');

        // Filter by type if provided
        if ($request->has('type') && $request->type !== null) {
            $query->where('type', $request->type);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $startDate = strtotime($request->start_date);
            $query->where('date', '>=', $startDate);
        }

        if ($request->has('end_date')) {
            $endDate = strtotime($request->end_date.' 23:59:59');
            $query->where('date', '<=', $endDate);
        }

        $sends = $query->get();

        $sends->transform(function ($send) {
            return [
                'id' => $send->id,
                'date' => $send->date,
                'type' => $send->type,
                'type_label' => $send->type == 0 ? 'Pickup' : 'Delivery',
                'status' => $send->status,
                'courier_name' => $send->user->name ?? null,
                'courier_phone' => $send->user->phone ?? null,
                'order_code' => $send->order->code ?? null,
                'customer_name' => $send->order->customer->name ?? null,
                'customer_phone' => $send->order->customer->phone ?? null,
                'customer_address' => $send->order->customer->address ?? null,
                'customer_maps' => $send->order->customer->maps ?? null,
                'item_name' => $send->type == 1 ? ($send->orderItem->name ?? null) : null,
                'project_name' => $send->project->name ?? null,
                'created_at' => $send->created_at,
                'modified_at' => $send->modified_at ?? null,
            ];
        });

        return response()->json([
            'data' => $sends,
        ]);
    }

    /**
     * Get available couriers (users)
     */
    public function getAvailableCouriers(Request $request)
    {
        // Get all active users (couriers)
        // BranchScoped trait will automatically filter by branch
        $couriers = User::orderBy('name', 'ASC')
            ->get(['id', 'name', 'phone', 'email']);

        return response()->json($couriers);
    }

    /**
     * Update status to completed (batch)
     */
    public function markAsCompleted(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:sends,id',
        ]);

        DB::beginTransaction();
        try {
            $sends = Send::whereIn('id', $validated['ids'])->get();

            foreach ($sends as $send) {
                $send->update([
                    'status' => 1,
                    'modified_by' => auth()->id(),
                ]);

                // Update related order/item status
                if ($send->type == 0) { // Pickup
                    Order::where('id', $send->orders_id)
                        ->update(['status' => 1]);
                } elseif ($send->type == 1 && $send->orders_items_id) { // Delivery
                    OrderItem::where('id', $send->orders_items_id)
                        ->update(['status' => 4]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Status pengiriman berhasil diupdate',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal mengupdate status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
