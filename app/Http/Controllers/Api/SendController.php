<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Send;
use App\Models\User;
use App\Services\CustomerPointService;
use App\Services\FcmService;
use App\Services\NotifikasiTugas;
use App\Services\ReportCacheService;
use App\Services\WhatsAppService;
use App\Support\FotoBase64;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendController extends Controller
{
    /**
     * Daftar tertutup alasan kegagalan. Kalau kurir boleh mengarang alasannya, pertanyaan
     * "kenapa pengantaran gagal" tidak akan pernah bisa dijawab dengan angka.
     */
    const REASON_CODES = ['customer_absent', 'wrong_address', 'rejected', 'rescheduled', 'other'];

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
                // Terisi hanya untuk tugas berstatus GAGAL. Tanpa ini kantor melihat
                // tugas gagal tanpa satu pun keterangan kenapa — dan itu mengembalikan
                // masalah yang jalur kegagalan ini dibuat untuk menyelesaikannya.
                'reason_code' => $send->reason_code,
                'fail_note' => $send->fail_note,
                'reschedule_date' => $send->reschedule_date,
                'failed_at' => $send->failed_at,
                'started_at' => $send->started_at,
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

        // Kurir tidak boleh menugaskan orang lain: apa pun users_id yang dikirim klien
        // ditimpa dengan dirinya sendiri. Select yang ter-disable di layar hanya petunjuk;
        // ini pagarnya. Admin tetap bebas memilih siapa pun.
        if (! $this->isAdmin($request)) {
            $validated['users_id'] = $request->user()->id;
        }

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

            // Setelah commit, bukan sebelum: notifikasi yang terkirim untuk tugas yang
            // ternyata gagal disimpan akan mengirim kurir ke layar yang isinya tidak ada.
            // Kegagalan FCM sendiri ditelan di dalam service — tugas tetap tersimpan
            // walau notifikasinya tidak sampai.
            app(NotifikasiTugas::class)->tugasBaru((int) $send->users_id, (int) $send->id);

            // Load relationships for response
            $send->load(['user', 'order.customer', 'orderItem']);

            // Send notifications (best-effort: the send is already committed above,
            // so a notification failure must never bubble up into a 500 response).
            try {
                $customer = $send->order->customer ?? null;
                $courier = $send->user ?? null;

                if ($customer && $customer->phone && $courier) {
                    $typeLabel = $send->type == 0 ? 'pickup' : 'pengiriman';
                    $courierPhone = $courier->phone ? "\nNomor Kurir: {$courier->phone}" : '';

                    // WhatsApp to customer. Customers are not FCM subscribers in this
                    // backend (FCM only targets user-{id} topics), so WA is the only
                    // customer-facing channel here.
                    $message = "Halo {$customer->name},\n\n"
                        ."Kurir kami *{$courier->name}* sedang dalam perjalanan untuk {$typeLabel} pesanan Anda.\n\n"
                        ."Order: *{$send->order->code}*{$courierPhone}\n\n"
                        ."Anda bisa pantau lokasi kurir secara real-time di:\n"
                        ."https://customer.shoesfast.id\n\n"
                        ."Login menggunakan nomor WhatsApp Anda untuk melihat tracking kurir.\n\n"
                        .'Terima kasih! 🙏';

                    $this->whatsapp->sendMessage($customer->phone, $message);
                }

                // FCM notification to courier (teknisi/kurir)
                if ($courier) {
                    $typeIcon = $send->type == 0 ? '📦' : '🚚';
                    $typeLabel = $send->type == 0 ? 'Pickup' : 'Delivery';
                    $title = "{$typeIcon} {$typeLabel} Baru Untukmu {$courier->name}";
                    $body = "Kamu mendapatkan tugas {$typeLabel} untuk order {$send->order->code}. Jangan lupa dicek...";

                    $this->fcm->sendUserNotification($courier->id, $title, $body, 'delivery');
                }
            } catch (\Throwable $e) {
                Log::warning('Send notification failed (send already saved)', [
                    'send_id' => $send->id,
                    'error' => $e->getMessage(),
                ]);
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

        // Kurir hanya boleh menyunting tugas MILIKNYA atau yang belum berpemilik.
        //
        // Tanpa pagar ini, rute ini menjadi jalan merebut: users_id memang ditimpa dengan
        // diri sendiri untuk non-admin (lihat di bawah), tapi tanpa memeriksa siapa
        // pemilik sebelumnya — jadi kurir bisa memindahkan tugas kurir lain ke dirinya
        // hanya dengan menebak sebuah id, dan tidak ada yang akan tahu. Mengambil tugas
        // antrean punya jalurnya sendiri sekarang: POST /sends/{id}/claim.
        if (! $this->isAdmin($request)
            && $send->users_id !== null
            && (int) $send->users_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'users_id' => 'required|exists:users,id', // Make courier required on update
            'date' => 'sometimes|date',
            'status' => 'sometimes|integer|in:0,1',
        ]);

        // Sama seperti store: kurir tidak boleh memindahkan tugas ke orang lain.
        if (! $this->isAdmin($request)) {
            $validated['users_id'] = $request->user()->id;
        }

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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
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
            // `id` sebagai pemecah seri: tanpa urutan yang stabil, dua baris bertanggal
            // sama bisa bertukar tempat antar permintaan, sehingga satu baris muncul dua
            // kali di halaman berikutnya sementara baris lain hilang sama sekali.
            ->orderBy('date', 'DESC')
            ->orderBy('id', 'DESC');

        // Kurir hanya melihat pengantaran miliknya sendiri; admin melihat semuanya.
        if (! $this->isAdmin($request)) {
            $query->where('users_id', $request->user()->id);
        }

        // Filter by type if provided
        if ($request->has('type') && $request->type !== null) {
            $query->where('type', $request->type);
        }

        // Pencarian dikerjakan server sejak daftar ini dipaginasi. Menyaring di klien
        // hanya akan menyaring halaman yang sedang terbuka, sehingga kotak cari berbohong:
        // tugas yang dicari ada di halaman tiga dan hasilnya tampak kosong.
        if ($request->filled('search')) {
            $cari = $request->input('search');
            $query->where(function ($q) use ($cari) {
                $q->whereHas('order', function ($qOrder) use ($cari) {
                    $qOrder->where('code', 'LIKE', "%{$cari}%")
                        ->orWhereHas('customer', function ($qCustomer) use ($cari) {
                            $qCustomer->where('name', 'LIKE', "%{$cari}%")
                                ->orWhere('phone', 'LIKE', "%{$cari}%");
                        });
                });
            });
        }

        // Paginator, bentuk yang sama persis dengan GET /treatments — satu pola untuk
        // seluruh daftar, bukan dua yang harus diingat mana dipakai di mana.
        $sends = $query->paginate($request->input('per_page', 15));
        $statusBayar = $this->statusPembayaranPerOrder($sends->getCollection());

        $sends->getCollection()->transform(function ($send) use ($statusBayar) {
            return [
                'id' => $send->id,
                'date' => $send->date,
                'type' => $send->type,
                'type_label' => $send->type == 0 ? 'Pickup' : 'Delivery',
                'status' => $send->status,
                'payment_status' => $statusBayar[$send->orders_id] ?? null,
                // Layar Barang di aplikasi kurir dibuka dengan orders_id
                // (GET /orders/{orderId}/items). Tanpa kunci ini tombolnya tidak
                // pernah bisa muncul — kode invoice saja tidak cukup.
                'orders_id' => $send->orders_id,
                'orders_items_id' => $send->orders_items_id,
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

        return response()->json($sends);
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
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
            // `id` sebagai pemecah seri: tanpa urutan yang stabil, dua baris bertanggal
            // sama bisa bertukar tempat antar permintaan, sehingga satu baris muncul dua
            // kali di halaman berikutnya sementara baris lain hilang sama sekali.
            ->orderBy('date', 'DESC')
            ->orderBy('id', 'DESC');

        // Kurir hanya melihat pengantaran miliknya sendiri; admin melihat semuanya.
        if (! $this->isAdmin($request)) {
            $query->where('users_id', $request->user()->id);
        }

        // Filter by type if provided
        if ($request->has('type') && $request->type !== null) {
            $query->where('type', $request->type);
        }

        // Pencarian dikerjakan server sejak daftar ini dipaginasi. Menyaring di klien
        // hanya akan menyaring halaman yang sedang terbuka, sehingga kotak cari berbohong:
        // tugas yang dicari ada di halaman tiga dan hasilnya tampak kosong.
        if ($request->filled('search')) {
            $cari = $request->input('search');
            $query->where(function ($q) use ($cari) {
                $q->whereHas('order', function ($qOrder) use ($cari) {
                    $qOrder->where('code', 'LIKE', "%{$cari}%")
                        ->orWhereHas('customer', function ($qCustomer) use ($cari) {
                            $qCustomer->where('name', 'LIKE', "%{$cari}%")
                                ->orWhere('phone', 'LIKE', "%{$cari}%");
                        });
                });
            });
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

        // Paginator, bentuk yang sama persis dengan GET /treatments — satu pola untuk
        // seluruh daftar, bukan dua yang harus diingat mana dipakai di mana.
        $sends = $query->paginate($request->input('per_page', 15));
        $statusBayar = $this->statusPembayaranPerOrder($sends->getCollection());

        $sends->getCollection()->transform(function ($send) use ($statusBayar) {
            return [
                'id' => $send->id,
                'date' => $send->date,
                'type' => $send->type,
                'type_label' => $send->type == 0 ? 'Pickup' : 'Delivery',
                'status' => $send->status,
                'payment_status' => $statusBayar[$send->orders_id] ?? null,
                // Layar Barang di aplikasi kurir dibuka dengan orders_id
                // (GET /orders/{orderId}/items). Tanpa kunci ini tombolnya tidak
                // pernah bisa muncul — kode invoice saja tidak cukup.
                'orders_id' => $send->orders_id,
                'orders_items_id' => $send->orders_items_id,
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

        return response()->json($sends);
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

    /**
     * POST /api/sends/{id}/reorder
     * Jemput ulang: kurir di lapangan menemukan barang yang perlu dijemput lagi. Dari satu
     * baris `sends` yang sudah ada, dibuatkan pesanan baru sekalian tugas jemputnya.
     *
     * Pelanggannya ditelusuri sendiri lewat sends -> orders -> customers. Klien tidak
     * mengirim `customers_id`, `users_id`, maupun `projects_id`: kurirnya adalah pemegang
     * token, cabangnya ditentukan BranchScoped. Kodenya memakai generator yang sama dengan
     * layar pesanan (Order::generateCode) — dua generator berarti dua invoice bisa memakai
     * nomor yang sama.
     */
    public function reorder(Request $request, $id)
    {
        $send = Send::with('order')->findOrFail($id);
        $customersId = $send->order->customers_id ?? null;

        if (! $customersId) {
            return response()->json([
                'message' => 'Pengiriman ini tidak terhubung ke pelanggan mana pun.',
            ], 422);
        }

        $user = $request->user();

        // Pesanan tanpa tugas jemput adalah pesanan hantu: tidak muncul di waiting list mana
        // pun dan tidak ada yang menjemputnya. Keduanya jadi satu transaksi.
        DB::beginTransaction();
        try {
            $order = Order::create([
                'customers_id' => $customersId,
                'code' => Order::generateCode(),
                'date' => time(),
                'total_price' => 0,
                'total_discount' => 0,
                'status' => 0, // pending
                'created_by' => $user->id,
            ]);

            // type 0 = jemput; 1 = antar (lihat index() dan markAsCompleted()).
            $jemput = Send::create([
                'orders_id' => $order->id,
                'users_id' => $user->id,
                'date' => time(),
                'status' => 0, // belum selesai
                'type' => 0,
                'created_by' => $user->id,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal membuat pesanan jemput ulang',
                'error' => $e->getMessage(),
            ], 500);
        }

        ReportCacheService::invalidate(['sales', 'orders', 'receivables', 'customers']);

        return response()->json([
            'message' => 'Pesanan jemput ulang berhasil dibuat',
            'data' => [
                'orders_id' => $order->id,
                'order_code' => $order->code,
                'customers_id' => (int) $customersId,
                'sends_id' => $jemput->id,
                'users_id' => $jemput->users_id,
                'date' => $jemput->date,
                'type' => $jemput->type,
                'status' => $jemput->status,
            ],
        ], 201);
    }

    /**
     * GET /api/sends/{id}/detail
     * Rincian barang untuk kurir: apa saja yang dikerjakan, riwayatnya, dan kelengkapannya.
     *
     * Endpoint tersendiri, BUKAN memakai orders/{id}/items yang admin-only — endpoint itu
     * membuka seluruh isi pesanan termasuk harga, dan kurir tidak perlu tahu harga untuk
     * mengantar barang. Di sini sengaja tidak ada satu pun angka rupiah.
     */
    public function detail(Request $request, $id)
    {
        $send = Send::with([
            'order.customer',
            'orderItem.treatments.service',
            'orderItem.treatments.user' => function ($q) {
                $q->withoutGlobalScopes();
            },
        ])->findOrFail($id);

        // Kurir hanya boleh membuka pengantaran miliknya sendiri.
        if (! $this->isAdmin($request) && (int) $send->users_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Ini bukan pengiriman Anda.'], 403);
        }

        $item = $send->orderItem;

        // `checkbox` disimpan sebagai "true, false, ..." sepanjang daftar kelengkapan.
        // Labelnya ditentukan jenis barang: 1 = Tas (7 item), selain itu Sepatu (3 item).
        $labelTas = ['Dust Bag', 'Care Card/Card', 'Tali panjang', 'Tali pendek', 'Tag Brand', 'Price tag', 'Receipt'];
        $labelSepatu = ['Tali Sepatu', 'Kaos Kaki', 'Box Sepatu'];
        $label = ((int) ($item->type ?? 0)) === 1 ? $labelTas : $labelSepatu;

        $nilai = $item && $item->checkbox
            ? array_map(fn ($v) => trim($v) === 'true', explode(',', $item->checkbox))
            : [];

        $kelengkapan = [];
        foreach ($label as $i => $nama) {
            $kelengkapan[] = ['nama' => $nama, 'ada' => $nilai[$i] ?? false];
        }

        // Kurir yang mengantar perlu tahu masih ada tagihan atau tidak — angka ini satu-satunya
        // rupiah di endpoint ini, dan rumusnya disalin dari PaymentController.php:77-105 supaya
        // tidak pernah berselisih dengan layar pembayaran. Harga per item tetap tidak dikirim.
        $order = $send->order;
        $totalPaid = $order ? Payment::where('orders_id', $order->id)->sum('nominal') : 0;

        // Harga BOLEH belum ada: pesanan dari portal pelanggan lahir tanpa harga, dan
        // petugas menentukannya setelah barang diperiksa. Sebelumnya null dipaksa jadi 0,
        // sehingga credit = 0 dan pesanan yang belum ditagih dilaporkan 'paid' — kurir
        // menyerahkan barang tanpa menagih. Keadaan itu sekarang punya namanya sendiri.
        $belumBerharga = $order === null || $order->total_price === null || (int) $order->total_price === 0;
        $totalPrice = $belumBerharga ? null : (int) $order->total_price;
        $credit = $belumBerharga ? null : $totalPrice - $totalPaid;

        return response()->json([
            'id' => $send->id,
            'type' => $send->type,
            'total_price' => $totalPrice,
            'total_paid' => $totalPaid,
            'credit' => $credit,
            'payment_status' => $belumBerharga
                ? 'unpriced'
                : ($credit <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid')),
            'order_code' => $send->order->code ?? null,
            'customer_name' => $send->order->customer->name ?? null,
            'customer_address' => $send->order->customer->address ?? null,
            'item_name' => $item->name ?? null,
            'item_photo' => $item && $item->photo
                ? (filter_var($item->photo, FILTER_VALIDATE_URL) ? $item->photo : asset('storage/'.$item->photo))
                : null,
            'item_note' => $item->note ?? null,
            'kelengkapan' => $kelengkapan,
            'pengerjaan' => $item
                ? $item->treatments->map(fn ($t) => [
                    'nama' => $t->service->name ?? null,
                    'status' => (int) $t->status,
                    'teknisi' => $t->user->name ?? null,
                    'mulai' => $t->date_start,
                    'selesai' => $t->done_at,
                ])->values()
                : [],
        ]);
    }

    /**
     * Tugas yang boleh disentuh pemegang token ini, atau null.
     *
     * Tugas milik kurir lain sengaja dijawab null -> 404, bukan 403: jawaban "ada, tapi bukan
     * milikmu" tetap membocorkan nomor tugas mana yang hidup. (detail() di atas masih memakai
     * 403; itu endpoint yang lebih tua, bukan maksud yang berbeda.)
     */
    /**
     * POST /api/sends/{id}/claim
     *
     * Kurir mengambil satu tugas dari antrean jemput/antar — langsung jadi miliknya,
     * tanpa menunggu kantor menugaskan. Padanan `POST /treatments/claim` untuk teknisi.
     *
     * Sebelum ini satu-satunya jalan menugaskan diri sendiri adalah lewat rute edit
     * (`PUT /sends/{id}`), yang menuntut seluruh badan formulir DAN tidak memeriksa
     * apakah tugasnya sudah dipegang orang — jadi kurir bisa merebut tugas kurir lain
     * tanpa siapa pun tahu.
     */
    public function claimTask(Request $request, $id)
    {
        $send = Send::where('id', $id)->first();

        if (! $send) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        if ($send->status !== Send::STATUS_BERJALAN) {
            return response()->json([
                'message' => 'Tugas ini sudah tidak berjalan.',
            ], 422);
        }

        // Sudah dipegang orang: jangan rebut. Sama seperti TreatmentController::claim.
        if ($send->users_id !== null && (int) $send->users_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Tugas ini sudah diambil kurir lain.',
            ], 422);
        }

        $send->update([
            'users_id' => $request->user()->id,
            'modified_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Tugas berhasil diambil.',
            'id' => $send->id,
            'users_id' => (int) $send->users_id,
        ]);
    }

    /**
     * Status pembayaran per orders_id untuk sekumpulan pengiriman.
     *
     * Nominalnya sengaja TIDAK ikut ke daftar — harga bukan urusan kurir. Yang dibawa
     * hanya cukup untuk membedakan "sudah lunas" dari "masih menagih" di kartu, supaya
     * kurir tidak perlu membuka detail satu per satu hanya untuk tahu perlu menagih.
     *
     * Dua query untuk seluruh halaman, bukan dua query per baris.
     *
     * @param  \Illuminate\Support\Collection  $sends
     * @return array<int, string>
     */
    private function statusPembayaranPerOrder($sends): array
    {
        $orderIds = $sends->pluck('orders_id')->filter()->unique()->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        $harga = Order::whereIn('id', $orderIds)->pluck('total_price', 'id');
        $terbayar = Payment::whereIn('orders_id', $orderIds)
            ->groupBy('orders_id')
            ->selectRaw('orders_id, SUM(nominal) as total')
            ->pluck('total', 'orders_id');

        $hasil = [];

        foreach ($orderIds as $id) {
            $totalPrice = $harga[$id] ?? null;

            // Harga NULL/0 bukan lunas — pesanan portal pelanggan lahir tanpa harga.
            if ($totalPrice === null || (int) $totalPrice === 0) {
                $hasil[$id] = 'unpriced';

                continue;
            }

            $paid = (int) ($terbayar[$id] ?? 0);
            $credit = (int) $totalPrice - $paid;

            $hasil[$id] = $credit <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        }

        return $hasil;
    }

    private function findTask(Request $request, $id): ?Send
    {
        $query = Send::where('id', $id);

        if (! $this->isAdmin($request)) {
            $query->where('users_id', $request->user()->id);
        }

        return $query->first();
    }

    /**
     * Simpan foto data-URL ke storage publik, kembalikan JALUR RELATIF ("sends/xxx.jpg").
     *
     * Jalur, bukan nama berkas telanjang: pembacanya cukup punya satu bentuk
     * (`asset('storage/'.$path)`) untuk semua folder — pola yang sudah dipakai
     * OrderController::uploadBase64Image dan dibaca lagi di deliveryWaitingList().
     * null berarti muatannya bukan gambar yang bisa didekode; pemanggil menjawab 422,
     * karena ini data dari perangkat lapangan dan bukan kesalahan server.
     */
    private function saveProofPhoto(string $dataUrl): ?string
    {
        if (! preg_match('/^data:image\/(\w+);base64,/', $dataUrl)) {
            return null;
        }

        try {
            return FotoBase64::simpan($dataUrl, 'sends');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * POST /api/sends/{id}/payment
     * Kurir menagih di depan pelanggan. Sebelum ini uangnya berpindah tangan tanpa jejak:
     * pelanggan merasa sudah bayar, tabel `payments` tidak tahu apa-apa.
     */
    public function recordPayment(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'method' => 'required|in:cash,transfer,qris',
            'note' => 'nullable|string',
        ]);

        $send = $this->findTask($request, $id);

        if (! $send) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        // Tugas gagal tidak boleh menerima uang — barangnya tidak berpindah tangan. Tugas
        // yang sudah SELESAI sengaja MASIH boleh: kurir kerap menekan "selesai" lebih dulu
        // lalu baru mencatat uangnya, dan menolaknya berarti uang itu hilang dari pembukuan.
        if ($send->status === Send::STATUS_GAGAL) {
            return response()->json([
                'message' => 'Tugas ini sudah ditandai gagal, pembayaran tidak bisa dicatat.',
            ], 422);
        }

        $order = $send->order;

        if (! $order) {
            return response()->json([
                'message' => 'Pengiriman ini tidak terhubung ke pesanan mana pun.',
            ], 422);
        }

        // Harga BOLEH belum ada — pesanan dari portal pelanggan lahir tanpa harga (lihat
        // detail()). Kalau nol dianggap harga, sisa tagihan jadi negatif dan pembayaran
        // apa pun tertolak dengan pesan yang membingungkan. Keadaannya disebut apa adanya.
        if ($order->total_price === null || (int) $order->total_price === 0) {
            return response()->json([
                'message' => 'Harga pesanan ini belum ditentukan, jadi belum ada yang bisa ditagih.',
            ], 422);
        }

        // Rumus sisa tagihan disalin dari detail(): dua rumus berarti layar kurir dan layar
        // kasir suatu saat akan menyebut angka yang berbeda untuk pesanan yang sama.
        $totalPrice = (int) $order->total_price;
        $totalPaid = (int) Payment::where('orders_id', $order->id)->sum('nominal');
        $credit = $totalPrice - $totalPaid;

        if ((int) $validated['amount'] > $credit) {
            return response()->json([
                'message' => 'Nominal melebihi sisa tagihan. Sisa tagihan Rp '.number_format(max($credit, 0), 0, ',', '.').'.',
            ], 422);
        }

        // `payments` tidak punya kolom metode pembayaran, dan menambah kolom ke tabel lama
        // yang dibaca seluruh laporan keuangan lebih berisiko daripada nilainya: metodenya
        // di sini hanya informatif — tidak ada rekonsiliasi yang membacanya, uangnya sudah
        // masuk lewat jalan mana pun. Jadi ditempel ke `note` dalam bentuk terbaca manusia.
        $labelMetode = ['cash' => 'Tunai', 'transfer' => 'Transfer', 'qris' => 'QRIS'];
        $note = 'Bayar di tempat ('.$labelMetode[$validated['method']].')';

        if (! empty($validated['note'])) {
            $note .= ' — '.$validated['note'];
        }

        DB::transaction(fn () => Payment::create([
            'orders_id' => $order->id,
            'date' => time(),
            'nominal' => (int) $validated['amount'],
            'note' => $note,
            'created_by' => $request->user()->id,
        ]));

        // Wajib, dan wajib DI LUAR transaksi di atas — servicenya membuka transaksinya
        // sendiri dengan lockForUpdate. Tanpa baris ini pelanggan yang melunasi lewat kurir
        // tidak pernah dapat poin, dan tidak ada satu pun layar yang akan memperlihatkannya.
        app(CustomerPointService::class)->awardIfSettled((int) $order->id);

        ReportCacheService::invalidate(['payments', 'receivables', 'cash-flow', 'profit-loss']);

        $totalPaid += (int) $validated['amount'];
        $credit = $totalPrice - $totalPaid;

        return response()->json([
            'total_paid' => $totalPaid,
            'credit' => $credit,
            'payment_status' => $credit <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    /**
     * POST /api/sends/{id}/proof
     * Bukti serah terima: foto, nama penerima, dan koordinat saat barang diserahkan.
     */
    public function storeProof(Request $request, $id)
    {
        $validated = $request->validate([
            'photo' => ['required', 'string', 'regex:/^data:image\/(\w+);base64,/'],
            'receiver_name' => 'required|string|max:100',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $send = $this->findTask($request, $id);

        if (! $send) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        // Selama tugas masih berjalan, bukti boleh dikirim ulang dan menimpa yang lama —
        // foto pertama sering buram atau salah orang. Setelah tugas ditutup (selesai/gagal)
        // buktinya adalah catatan yang sudah jadi, bukan draf.
        if ($send->status !== Send::STATUS_BERJALAN) {
            return response()->json([
                'message' => 'Bukti hanya bisa dikirim untuk tugas yang masih berjalan.',
            ], 422);
        }

        $path = $this->saveProofPhoto($validated['photo']);

        if (! $path) {
            return response()->json(['message' => 'Foto bukti tidak bisa dibaca.'], 422);
        }

        $send->update([
            'proof_photo' => $path,
            'receiver_name' => $validated['receiver_name'],
            'proof_latitude' => $validated['latitude'] ?? null,
            'proof_longitude' => $validated['longitude'] ?? null,
            'proof_at' => time(),
            'modified_by' => $request->user()->id,
        ]);

        return response()->json([
            'photo_url' => asset('storage/'.$path),
        ]);
    }

    /**
     * POST /api/sends/{id}/failed
     * Tugas yang tidak bisa diselesaikan. Sebelum ini kegagalan tidak punya bentuk, jadi
     * tugas gagal menggantung selamanya sebagai "berjalan" di daftar kantor.
     */
    public function markFailed(Request $request, $id)
    {
        $validated = $request->validate([
            'reason_code' => 'required|in:'.implode(',', self::REASON_CODES),
            'note' => 'nullable|string',
            'reschedule_date' => 'required_if:reason_code,rescheduled|nullable|date',
            'photo' => ['nullable', 'string', 'regex:/^data:image\/(\w+);base64,/'],
        ]);

        $send = $this->findTask($request, $id);

        if (! $send) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        // Barang yang sudah diserahkan tidak bisa "gagal" belakangan; membalik status
        // selesai jadi gagal akan menghapus riwayat serah terima yang sah. Tugas yang
        // sudah gagal boleh ditandai ulang — kurir mengoreksi alasan yang salah pilih.
        if ($send->status === Send::STATUS_SELESAI) {
            return response()->json([
                'message' => 'Tugas yang sudah selesai tidak bisa ditandai gagal.',
            ], 422);
        }

        $update = [
            'status' => Send::STATUS_GAGAL,
            'failed_at' => time(),
            'reason_code' => $validated['reason_code'],
            'fail_note' => $validated['note'] ?? null,
            'reschedule_date' => ! empty($validated['reschedule_date'])
                ? strtotime($validated['reschedule_date'])
                : null,
            'modified_by' => $request->user()->id,
        ];

        if (! empty($validated['photo'])) {
            $path = $this->saveProofPhoto($validated['photo']);

            if (! $path) {
                return response()->json(['message' => 'Foto bukti tidak bisa dibaca.'], 422);
            }

            $update['proof_photo'] = $path;
        }

        $send->update($update);

        return response()->json([
            'message' => 'Tugas ditandai gagal',
            'status' => Send::STATUS_GAGAL,
            'failed_at' => $update['failed_at'],
            'reschedule_date' => $update['reschedule_date'],
        ]);
    }

    /**
     * POST /api/sends/{id}/start
     * Kurir menekan "berangkat". Jam berangkat inilah yang menjawab "kenapa lama".
     */
    public function start(Request $request, $id)
    {
        $send = $this->findTask($request, $id);

        if (! $send) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        if ($send->status !== Send::STATUS_BERJALAN) {
            return response()->json([
                'message' => 'Tugas ini sudah tidak berjalan, jam berangkat tidak bisa dicatat.',
            ], 422);
        }

        // Tombolnya ditekan dua-tiga kali saat sinyal buruk. Menimpa started_at akan
        // mengganti jam berangkat yang sebenarnya dengan jam ketika sinyal kembali, jadi
        // yang pertama menang dan tekanan berikutnya hanya membacakan ulang nilainya —
        // 200, bukan 422: kurirnya tidak melakukan kesalahan apa pun.
        if ($send->started_at === null) {
            $send->update([
                'started_at' => time(),
                'modified_by' => $request->user()->id,
            ]);
        }

        return response()->json([
            'started_at' => (int) $send->started_at,
        ]);
    }

    /**
     * GET /api/sends/summary?date=YYYY-MM-DD
     * Rekap sehari milik kurir yang sedang login.
     */
    public function summary(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $start = strtotime(date('Y-m-d', $request->date ? strtotime($request->date) : time()));
        $end = $start + 86400;
        $userId = $request->user()->id;

        // SELALU disaring ke pengguna yang login, admin sekalipun: pertanyaan yang dijawab
        // layar ini adalah "hari ini SAYA menyelesaikan berapa", bukan rekap cabang.
        $milikSaya = fn () => Send::where('users_id', $userId);

        $completed = $milikSaya()
            ->where('status', Send::STATUS_SELESAI)
            ->where('modified_at', '>=', $start)
            ->where('modified_at', '<', $end)
            ->count();

        $failed = $milikSaya()
            ->where('status', Send::STATUS_GAGAL)
            ->where('failed_at', '>=', $start)
            ->where('failed_at', '<', $end)
            ->count();

        // Sengaja TANPA filter tanggal, tidak seperti dua angka di atas: `completed` dan
        // `failed` adalah kejadian hari itu, sedangkan ini antrean yang masih menunggu.
        // Tugas kemarin yang belum tuntas justru paling perlu terlihat hari ini; menyaringnya
        // dengan tanggal akan menyembunyikannya tepat pada saat ia menumpuk.
        $pending = $milikSaya()
            ->where('status', Send::STATUS_BERJALAN)
            ->count();

        // Uang yang lewat tangan kurir ini hari itu. Subquery, bukan pluck(): daftar pesanan
        // seorang kurir tumbuh seumur pemakaian dan tidak ada gunanya dibawa ke PHP.
        $collected = (int) Payment::whereIn(
            'orders_id',
            Send::where('users_id', $userId)->select('orders_id')
        )
            ->where('date', '>=', $start)
            ->where('date', '<', $end)
            ->sum('nominal');

        return response()->json([
            'date' => date('Y-m-d', $start),
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $pending,
            'collected' => $collected,
        ]);
    }
}
