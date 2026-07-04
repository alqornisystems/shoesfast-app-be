<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Treatment;
use App\Services\ReportCacheService;
use App\Services\WablasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    protected WablasService $wablas;

    public function __construct(WablasService $wablas)
    {
        $this->wablas = $wablas;
    }

    /**
     * Display a listing of orders
     */
    public function index(Request $request)
    {
        $query = Order::with(['customer', 'project', 'sends'])
            ->orderBy('id', 'DESC');

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Search by code or customer name
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($qCustomer) use ($search) {
                        $qCustomer->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Filter by date range
        if ($request->has('date_from') && $request->date_from !== '') {
            $dateFrom = strtotime($request->date_from);
            $query->where('date', '>=', $dateFrom);
        }

        if ($request->has('date_to') && $request->date_to !== '') {
            $dateTo = strtotime($request->date_to.' 23:59:59');
            $query->where('date', '<=', $dateTo);
        }

        $perPage = $request->get('per_page', 15);
        $orders = $query->paginate($perPage);

        // Transform data
        $orders->getCollection()->transform(function ($order) {
            // Check if pickup send exists (type = 0)
            $hasPickup = $order->sends->where('type', 0)->isNotEmpty();

            return [
                'id' => $order->id,
                'code' => $order->code,
                'date' => $order->date,
                'total_discount' => $order->total_discount,
                'total_price' => $order->total_price,
                'note' => $order->note,
                'status' => $order->status,
                'due_date' => strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date).' +3 days'))),
                'has_pickup' => $hasPickup,
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'email' => $order->customer->email,
                    'address' => $order->customer->address,
                ] : null,
                'project_name' => $order->project ? $order->project->name : null,
                'created_at' => $order->created_at,
            ];
        });

        return response()->json($orders);
    }

    /**
     * Display the specified order
     */
    public function show($id)
    {
        $order = Order::with(['customer', 'items.treatments.service', 'items.treatments.user', 'items.sends.user', 'project', 'sends'])
            ->findOrFail($id);

        // Transform items
        $items = $order->items->map(function ($item) {
            $treatments = $item->treatments->map(function ($treatment) {
                return [
                    'id' => $treatment->id,
                    'services_id' => $treatment->services_id,
                    'name' => $treatment->service ? $treatment->service->name : null,
                    'price' => $treatment->price,
                    'estimation' => $treatment->service ? $treatment->service->estimation : null,
                    'date_start' => $treatment->date_start,
                    'date_end' => $treatment->date_end,
                    'status' => $treatment->status,
                    'users_id' => $treatment->users_id,
                    'users_name' => $treatment->user ? $treatment->user->name : null,
                ];
            });

            // Get delivery courier (type = 1)
            $deliverySend = $item->sends->where('type', 1)->first();
            $deliveryCourierName = $deliverySend && $deliverySend->user ? $deliverySend->user->name : null;

            // Convert photo path to full URL
            $photoUrl = null;
            if ($item->photo) {
                // Check if it's already a URL
                if (filter_var($item->photo, FILTER_VALIDATE_URL)) {
                    $photoUrl = $item->photo;
                } else {
                    // Convert storage path to URL
                    $photoUrl = asset('storage/'.$item->photo);
                }
            }

            return [
                'id' => $item->id,
                'photo' => $photoUrl,
                'name' => $item->name,
                'price' => $item->price,
                'discount' => $item->discount,
                'status' => $item->status,
                'type' => $item->type,
                'checkbox' => $item->checkbox,
                'note' => $item->note,
                'treatments' => $treatments,
                'delivery_courier_name' => $deliveryCourierName,
            ];
        });

        // Check if pickup send exists (type = 0)
        $hasPickup = $order->sends->where('type', 0)->isNotEmpty();

        return response()->json([
            'id' => $order->id,
            'code' => $order->code,
            'date' => $order->date,
            'total_discount' => $order->total_discount,
            'total_price' => $order->total_price,
            'note' => $order->note,
            'status' => $order->status,
            'due_date' => strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date).' +3 days'))),
            'has_pickup' => $hasPickup,
            'customer' => [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
                'address' => $order->customer->address,
                'maps' => $order->customer->maps,
            ],
            'project_name' => $order->project ? $order->project->name : null,
            'items' => $items,
            'created_at' => $order->created_at,
        ]);
    }

    /**
     * Store a newly created order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customers_id' => 'required|exists:customers,id',
            'date' => 'required|date',
            'note' => 'nullable|string',
            'items' => 'nullable|array', // Item tidak wajib, bisa dibuat order kosong dulu
            'items.*.name' => 'required|string|max:100',
            'items.*.price' => 'required|integer|min:0',
            'items.*.discount' => 'nullable|integer|min:0',
            'items.*.type' => 'required|integer|in:0,1,2',
            'items.*.photo' => 'nullable|string',
            'items.*.note' => 'nullable|string',
            'items.*.checkbox' => 'nullable|string',
            'items.*.services' => 'nullable|array',
            'items.*.services.*.services_id' => 'required|exists:services,id',
            'items.*.services.*.price' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Generate order code
            $code = $this->generateOrderCode();

            // Calculate total price from items (default 0 jika tidak ada items)
            $totalPrice = 0;
            $totalDiscount = 0;

            if (! empty($validated['items'])) {
                $totalPrice = collect($validated['items'])->sum(function ($item) {
                    return $item['price'] - ($item['discount'] ?? 0);
                });
                $totalDiscount = collect($validated['items'])->sum('discount');
            }

            // Create order
            $order = Order::create([
                'customers_id' => $validated['customers_id'],
                'code' => $code,
                'date' => strtotime($validated['date']),
                'total_price' => $totalPrice,
                'total_discount' => $totalDiscount,
                'note' => $validated['note'] ?? null,
                'status' => 0, // pending
                'created_by' => auth()->id(),
            ]);

            // Create order items and treatments (only if items exist)
            if (! empty($validated['items'])) {
                foreach ($validated['items'] as $index => $itemData) {
                    // Handle photo upload
                    $photoPath = null;
                    if (! empty($itemData['photo'])) {
                        $photoPath = $this->uploadBase64Image($itemData['photo'], "item-{$order->id}-".($index + 1));
                    }

                    $orderItem = OrderItem::create([
                        'orders_id' => $order->id,
                        'name' => $itemData['name'],
                        'price' => $itemData['price'],
                        'discount' => $itemData['discount'] ?? 0,
                        'type' => $itemData['type'],
                        'photo' => $photoPath,
                        'note' => $itemData['note'] ?? null,
                        'checkbox' => $itemData['checkbox'] ?? null,
                        'status' => 0,
                        'created_by' => auth()->id(),
                    ]);

                    // Create treatments (services)
                    if (! empty($itemData['services'])) {
                        $previousEndDate = null;
                        foreach ($itemData['services'] as $serviceIndex => $serviceData) {
                            $service = \App\Models\Service::find($serviceData['services_id']);

                            if ($serviceIndex === 0) {
                                $dateStart = time();
                                $dateEnd = strtotime("+{$service->estimation} day", $dateStart);
                            } else {
                                $dateStart = strtotime('+1 day', $previousEndDate);
                                $dateEnd = strtotime("+{$service->estimation} day", $dateStart);
                            }

                            Treatment::create([
                                'orders_items_id' => $orderItem->id,
                                'services_id' => $serviceData['services_id'],
                                'price' => $serviceData['price'],
                                'date_start' => $dateStart,
                                'date_end' => $dateEnd,
                                'status' => 0,
                                'created_by' => auth()->id(),
                            ]);

                            $previousEndDate = $dateEnd;
                        }
                    }
                }
            }

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'sales',
                'orders',
                'receivables',
                'hpp',
                'profit-loss',
                'cash-flow',
                'customers',
                'top-services',
                'treatments',
            ]);

            // Load relationships for response
            $order->load(['customer', 'items.treatments.service']);

            // Send WhatsApp notification to customer
            $customer = $order->customer;
            if ($customer && $customer->phone) {
                $message = "Halo {$customer->name},\n\n"
                    ."Pesanan Anda sudah masuk ke dalam sistem Shoesfast!\n\n"
                    ."Kode Order: *{$order->code}*\n"
                    .'Tanggal: '.date('d M Y', $order->date)."\n\n"
                    ."Anda bisa cek status pesanan di:\n"
                    ."https://customer.shoesfast.id\n\n"
                    ."Login menggunakan nomor WhatsApp Anda.\n\n"
                    .'Terima kasih! 🙏';

                $this->wablas->sendMessage($customer->phone, $message);
            }

            return response()->json([
                'message' => 'Pesanan berhasil dibuat',
                'data' => $order,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal membuat pesanan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified order
     */
    public function update(Request $request, $id)
    {
        $order = Order::with(['customer', 'project'])->findOrFail($id);
        $oldStatus = $order->status;

        $validated = $request->validate([
            'customers_id' => 'sometimes|exists:customers,id',
            'date' => 'sometimes|date',
            'note' => 'nullable|string',
            'status' => 'sometimes|integer|in:0,1,2,3',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['date'])) {
                $validated['date'] = strtotime($validated['date']);
            }

            $validated['modified_by'] = auth()->id();
            $order->update($validated);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'sales',
                'orders',
                'receivables',
                'hpp',
                'profit-loss',
                'cash-flow',
                'customers',
                'top-services',
            ]);

            // Send WhatsApp notification for Google Maps review when order is completed
            if (isset($validated['status']) && $validated['status'] == 2 && $oldStatus != 2) {
                $customer = $order->customer;
                $project = $order->project;

                if ($customer && $customer->phone && $project && $project->maps) {
                    $message = "Halo {$customer->name},\n\n"
                        ."Pesanan Anda dengan kode *{$order->code}* telah selesai! 🎉\n\n"
                        ."Terima kasih telah mempercayakan perawatan sepatu Anda kepada kami.\n\n"
                        ."Kami sangat menghargai feedback Anda! Jika Anda puas dengan layanan kami, mohon berikan review di Google Maps:\n\n"
                        ."{$project->maps}\n\n"
                        ."Review Anda sangat membantu kami untuk terus meningkatkan kualitas layanan.\n\n"
                        .'Terima kasih! 🙏';

                    $this->wablas->sendMessage($customer->phone, $message);
                }
            }

            return response()->json([
                'message' => 'Pesanan berhasil diupdate',
                'data' => $order->load(['customer', 'items']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal mengupdate pesanan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Soft delete the specified order
     */
    public function destroy($id)
    {
        $order = Order::findOrFail($id);

        DB::beginTransaction();
        try {
            // Soft delete order
            $order->update([
                'is_deleted' => 1,
                'modified_by' => auth()->id(),
            ]);

            // Soft delete all items
            OrderItem::where('orders_id', $order->id)
                ->update([
                    'is_deleted' => 1,
                    'modified_by' => auth()->id(),
                ]);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'sales',
                'orders',
                'receivables',
                'hpp',
                'profit-loss',
                'cash-flow',
                'customers',
                'top-services',
                'treatments',
            ]);

            return response()->json([
                'message' => 'Pesanan berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal menghapus pesanan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get order items
     */
    public function getItems($orderId)
    {
        $items = OrderItem::with(['treatments.service', 'treatments.user'])
            ->where('orders_id', $orderId)
            ->get();

        $items->transform(function ($item) {
            $treatments = $item->treatments->map(function ($treatment) {
                return [
                    'id' => $treatment->id,
                    'services_id' => $treatment->services_id,
                    'name' => $treatment->service ? $treatment->service->name : null,
                    'price' => $treatment->price,
                    'estimation' => $treatment->service ? $treatment->service->estimation : null,
                    'date_start' => $treatment->date_start,
                    'date_end' => $treatment->date_end,
                    'status' => $treatment->status,
                    'users_id' => $treatment->users_id,
                    'users_name' => $treatment->user ? $treatment->user->name : null,
                ];
            });

            // Convert photo path to full URL
            $photoUrl = null;
            if ($item->photo) {
                // Check if it's already a URL
                if (filter_var($item->photo, FILTER_VALIDATE_URL)) {
                    $photoUrl = $item->photo;
                } else {
                    // Convert storage path to URL
                    $photoUrl = asset('storage/'.$item->photo);
                }
            }

            return [
                'id' => $item->id,
                'photo' => $photoUrl,
                'name' => $item->name,
                'price' => $item->price,
                'discount' => $item->discount,
                'status' => $item->status,
                'type' => $item->type,
                'checkbox' => $item->checkbox,
                'checkboxModel' => $item->checkbox ? explode(', ', $item->checkbox) : [],
                'note' => $item->note,
                'treatments' => $treatments,
            ];
        });

        return response()->json($items);
    }

    /**
     * Add or update order item
     */
    public function saveItem(Request $request, $orderId)
    {
        $validated = $request->validate([
            'id' => 'nullable|exists:orders_items,id',
            'name' => 'required|string|max:100',
            'price' => 'required|integer|min:0',
            'discount' => 'nullable|integer|min:0',
            'type' => 'required|integer|in:0,1,2',
            'photo' => 'nullable|string',
            'note' => 'nullable|string',
            'checkbox' => 'nullable|string',
            'services' => 'nullable|array',
            'services.*.id' => 'nullable|exists:treatments,id',
            'services.*.services_id' => 'required|exists:services,id',
            'services.*.price' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            $order = Order::findOrFail($orderId);

            // Update or create item
            if (! empty($validated['id'])) {
                $orderItem = OrderItem::findOrFail($validated['id']);

                // Handle photo update
                if (! empty($validated['photo'])) {
                    // Delete old photo if exists
                    if ($orderItem->photo && Storage::exists($orderItem->photo)) {
                        Storage::delete($orderItem->photo);
                    }
                    $photoPath = $this->uploadBase64Image($validated['photo'], "item-{$orderId}-".rand(1000, 9999));
                    $validated['photo'] = $photoPath;
                }

                $validated['modified_by'] = auth()->id();
                $orderItem->update($validated);
            } else {
                // Handle photo upload for new item
                $photoPath = null;
                if (! empty($validated['photo'])) {
                    $photoPath = $this->uploadBase64Image($validated['photo'], "item-{$orderId}-".rand(1000, 9999));
                }

                $orderItem = OrderItem::create([
                    'orders_id' => $orderId,
                    'name' => $validated['name'],
                    'price' => $validated['price'],
                    'discount' => $validated['discount'] ?? 0,
                    'type' => $validated['type'],
                    'photo' => $photoPath,
                    'note' => $validated['note'] ?? null,
                    'checkbox' => $validated['checkbox'] ?? null,
                    'status' => 0,
                    'created_by' => auth()->id(),
                ]);

                // Update order total price
                $order->total_price += $validated['price'];
                $order->total_discount += ($validated['discount'] ?? 0);
                $order->save();
            }

            // Handle services/treatments
            if (! empty($validated['services'])) {
                // Get all existing treatments for this item
                $existingTreatments = Treatment::where('orders_items_id', $orderItem->id)->get();

                // Get IDs of treatments that should be kept
                $keepTreatmentIds = collect($validated['services'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                // Soft delete treatments that are not in the new list
                $existingTreatments->each(function ($treatment) use ($keepTreatmentIds) {
                    if (! in_array($treatment->id, $keepTreatmentIds)) {
                        // Only delete if no technician assigned
                        if (! $treatment->users_id) {
                            $treatment->update([
                                'is_deleted' => 1,
                                'modified_by' => auth()->id(),
                            ]);
                        }
                    }
                });

                // Calculate dates for sequential treatments
                $previousEndDate = null;
                foreach ($validated['services'] as $index => $serviceData) {
                    $service = \App\Models\Service::find($serviceData['services_id']);

                    // Calculate date_start and date_end
                    if ($index === 0) {
                        $dateStart = time();
                        $dateEnd = strtotime("+{$service->estimation} day", $dateStart);
                    } else {
                        $dateStart = strtotime('+1 day', $previousEndDate);
                        $dateEnd = strtotime("+{$service->estimation} day", $dateStart);
                    }

                    if (! empty($serviceData['id'])) {
                        // Update existing treatment (only if no technician assigned)
                        $existingTreatment = Treatment::find($serviceData['id']);
                        if ($existingTreatment && ! $existingTreatment->users_id) {
                            $existingTreatment->update([
                                'services_id' => $serviceData['services_id'],
                                'price' => $serviceData['price'],
                                'date_start' => $dateStart,
                                'date_end' => $dateEnd,
                                'modified_by' => auth()->id(),
                            ]);
                        }
                        // If technician assigned, just update dates
                        elseif ($existingTreatment) {
                            $existingTreatment->update([
                                'date_start' => $dateStart,
                                'date_end' => $dateEnd,
                                'modified_by' => auth()->id(),
                            ]);
                        }
                    } else {
                        // Create new treatment
                        Treatment::create([
                            'orders_items_id' => $orderItem->id,
                            'services_id' => $serviceData['services_id'],
                            'price' => $serviceData['price'],
                            'date_start' => $dateStart,
                            'date_end' => $dateEnd,
                            'status' => 0,
                            'created_by' => auth()->id(),
                        ]);
                    }

                    $previousEndDate = $dateEnd;
                }
            }

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'sales',
                'orders',
                'receivables',
                'hpp',
                'profit-loss',
                'cash-flow',
                'customers',
                'top-services',
                'treatments',
            ]);

            return response()->json([
                'message' => 'Item berhasil disimpan',
                'data' => $orderItem->load(['treatments.service']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal menyimpan item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove order item
     */
    public function removeItem($orderId, $itemId)
    {
        DB::beginTransaction();
        try {
            $orderItem = OrderItem::findOrFail($itemId);
            $order = Order::findOrFail($orderId);

            // Update order total price
            $order->total_price -= $orderItem->price;
            $order->total_discount -= $orderItem->discount;
            $order->save();

            // Soft delete item
            $orderItem->update([
                'is_deleted' => 1,
                'modified_by' => auth()->id(),
            ]);

            // Soft delete treatments
            Treatment::where('orders_items_id', $itemId)
                ->update([
                    'is_deleted' => 1,
                    'modified_by' => auth()->id(),
                ]);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'sales',
                'orders',
                'receivables',
                'hpp',
                'profit-loss',
                'cash-flow',
                'customers',
                'top-services',
                'treatments',
            ]);

            return response()->json([
                'message' => 'Item berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal menghapus item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search customers for order creation
     */
    public function searchCustomers(Request $request)
    {
        $search = $request->get('search', '');

        $customers = Customer::where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
                ->orWhere('phone', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%");
        })
            ->limit(10)
            ->get();

        return response()->json($customers);
    }

    /**
     * Search services for order item
     */
    public function searchServices(Request $request)
    {
        $search = $request->get('search', '');

        $services = \App\Models\Service::where('name', 'LIKE', "%{$search}%")
            ->limit(10)
            ->get(['id', 'name', 'price', 'estimation']);

        return response()->json($services);
    }

    /**
     * Get available pickup orders (status = 0)
     */
    public function getAvailablePickupOrders(Request $request)
    {
        $orders = Order::with('customer')
            ->where('status', 0)
            ->orderBy('date', 'DESC')
            ->limit(20)
            ->get();

        return response()->json($orders->map(function ($order) {
            return [
                'id' => $order->id,
                'code' => $order->code,
                'customer_name' => $order->customer->name,
                'customer_phone' => $order->customer->phone,
                'customer_address' => $order->customer->address,
                'date' => $order->date,
            ];
        }));
    }

    /**
     * Generate unique order code
     * Format: INV{YYYYMM}{0001}
     * Example: INV2026030001
     */
    private function generateOrderCode()
    {
        $prefix = 'INV';
        $yearMonth = date('Ym'); // YYYYMM format

        // Get last order code for this month
        $lastOrder = Order::where('code', 'LIKE', "{$prefix}{$yearMonth}%")
            ->orderBy('code', 'DESC')
            ->first();

        if ($lastOrder) {
            // Extract last 4 digits
            $lastNumber = (int) substr($lastOrder->code, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix.$yearMonth.str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Upload base64 image
     */
    private function uploadBase64Image($base64String, $filename)
    {
        // Extract base64 data
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $base64String = substr($base64String, strpos($base64String, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif

            $base64String = str_replace(' ', '+', $base64String);
            $imageData = base64_decode($base64String);

            if ($imageData === false) {
                throw new \Exception('base64_decode failed');
            }

            $filename = $filename.'.'.$type;
            $path = 'orders_items/'.$filename;

            Storage::disk('public')->put($path, $imageData);

            return $path;
        }

        throw new \Exception('Invalid image format');
    }
}
