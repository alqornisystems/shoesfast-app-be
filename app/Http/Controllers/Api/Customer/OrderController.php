<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Send;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Label untuk pelanggan. Admin panel memberi label 3 = "Dibatalkan"
     * (order-client.tsx:81), padahal 2.448 pesanan berstatus 3 lunas dibayar.
     * Pembatalan sebenarnya memakai is_deleted, bukan status.
     */
    private const STATUS_LABELS = [
        0 => 'Menunggu diproses',
        1 => 'Sedang dikerjakan',
        2 => 'Siap diambil',
        3 => 'Selesai',
    ];

    private const SHOE_CHECKLIST = ['Tali Sepatu', 'Kaos Kaki', 'Box Sepatu'];

    private const BAG_CHECKLIST = [
        'Dust Bag', 'Care Card/Card', 'Tali panjang', 'Tali pendek',
        'Tag Brand', 'Price tag', 'Receipt',
    ];

    /**
     * Cakupan cabang dipaksakan eksplisit, tidak menumpang BranchScoped.
     * BranchContext membaca Auth::user()->projects_id, dan menggantungkan
     * isolasi data pelanggan pada perilaku yang dirancang untuk staf adalah
     * ketergantungan yang tidak dijanjikan siapa pun.
     */
    private function scopedOrders(Customer $customer)
    {
        return Order::withoutGlobalScope('branch')
            ->where('customers_id', $customer->id)
            ->where('projects_id', $customer->projects_id);
    }

    // GET /api/customer/orders
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();
        $perPage = min((int) $request->input('per_page', 10), 50);

        $paginator = $this->scopedOrders($customer)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (Order $order) => [
            'id' => $order->id,
            'code' => $order->code,
            'date' => $order->date,
            'status' => (int) $order->status,
            'status_label' => self::STATUS_LABELS[(int) $order->status] ?? 'Tidak diketahui',
            'total_price' => (int) $order->total_price,
        ]);

        return response()->json($paginator);
    }

    // GET /api/customer/orders/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $order = $this->scopedOrders($customer)->find($id);

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan'], 404);
        }

        $items = OrderItem::withoutGlobalScope('branch')
            ->with(['treatments' => fn ($q) => $q->withoutGlobalScope('branch'), 'treatments.service'])
            ->where('orders_id', $order->id)
            ->get();

        $totalPaid = (int) Payment::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)
            ->sum('nominal');

        return response()->json([
            'id' => $order->id,
            'code' => $order->code,
            'date' => $order->date,
            'status' => (int) $order->status,
            'status_label' => self::STATUS_LABELS[(int) $order->status] ?? 'Tidak diketahui',
            'total_price' => (int) $order->total_price,
            'total_paid' => $totalPaid,
            'credit' => (int) $order->total_price - $totalPaid,
            'pickup_address' => $order->pickup_address,
            'pickup_maps' => $order->pickup_maps,
            'items' => $items->map(fn (OrderItem $item) => $this->presentItem($item))->values(),
            'timeline' => $this->timeline($order, $items),
        ]);
    }

    // GET /api/customer/orders/{id}/invoice
    public function invoice(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $order = $this->scopedOrders($customer)->find($id);

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan'], 404);
        }

        $payments = Payment::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)
            ->orderBy('date')
            ->get();

        $totalPaid = (int) $payments->sum('nominal');
        $credit = (int) $order->total_price - $totalPaid;

        // Disalin dari PaymentController::index dan PublicInvoiceController
        // supaya ketiganya tidak pernah berbeda angka untuk pesanan yang sama.
        $dueDate = strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date).' +3 days')));

        return response()->json([
            'code' => $order->code,
            'date' => $order->date,
            'due_date' => $dueDate,
            'payment_status' => $credit === 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid'),
            'total_price' => (int) $order->total_price,
            'total_paid' => $totalPaid,
            'credit' => $credit,
            'payments' => $payments->map(fn (Payment $payment) => [
                'date' => $payment->date,
                'nominal' => (int) $payment->nominal,
                'note' => $payment->note,
            ])->values(),
        ]);
    }

    private function presentItem(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'type' => (int) $item->type,
            'photo' => $item->photo
                ? (filter_var($item->photo, FILTER_VALIDATE_URL) ? $item->photo : asset('storage/'.$item->photo))
                : null,
            'kelengkapan' => $this->kelengkapan($item),
            'note' => $item->note,
            'treatments' => $item->treatments->map(fn ($treatment) => [
                'name' => $treatment->service?->name,
                'status' => (int) $treatment->status,
                'date_start' => $treatment->date_start,
                'done_at' => $treatment->done_at,
            ])->values(),
        ];
    }

    /**
     * checkbox disimpan sebagai deretan boolean berkoma menurut urutan tetap.
     * Daftarnya harus persis sama dengan order-form-client.tsx di admin panel,
     * kalau tidak kedua aplikasi berbeda tafsir atas baris yang sama.
     */
    private function kelengkapan(OrderItem $item): array
    {
        $labels = ((int) $item->type === 1) ? self::BAG_CHECKLIST : self::SHOE_CHECKLIST;

        if (! $item->checkbox) {
            return [];
        }

        $flags = array_map(fn ($value) => trim($value) === 'true', explode(',', $item->checkbox));

        $result = [];
        foreach ($labels as $index => $label) {
            if ($flags[$index] ?? false) {
                $result[] = $label;
            }
        }

        return $result;
    }

    private function timeline(Order $order, $items): array
    {
        $timeline = [[
            'key' => 'dibuat',
            'label' => 'Pesanan dibuat',
            'date' => $order->date,
            'done' => true,
        ]];

        $pickup = Send::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)->where('type', 0)
            ->orderBy('date')->first();

        if ($pickup) {
            $timeline[] = [
                'key' => 'dijemput',
                'label' => 'Dijemput kurir',
                'date' => $pickup->date,
                'done' => (int) $pickup->status === 1,
            ];
        }

        $treatments = $items->flatMap(fn (OrderItem $item) => $item->treatments);
        if ($treatments->isNotEmpty()) {
            $timeline[] = [
                'key' => 'dikerjakan',
                'label' => 'Pengerjaan',
                'date' => $treatments->min('date_start'),
                'done' => $treatments->every(fn ($treatment) => (int) $treatment->status === 2),
            ];
        }

        $delivery = Send::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)->where('type', 1)
            ->orderBy('date')->first();

        if ($delivery) {
            $timeline[] = [
                'key' => 'diantar',
                'label' => 'Diantar kurir',
                'date' => $delivery->date,
                'done' => (int) $delivery->status === 1,
            ];
        }

        $timeline[] = [
            'key' => 'selesai',
            'label' => 'Selesai',
            'date' => null,
            'done' => (int) $order->status === 3,
        ];

        return $timeline;
    }
}
