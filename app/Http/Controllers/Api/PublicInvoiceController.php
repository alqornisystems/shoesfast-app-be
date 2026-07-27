<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;

class PublicInvoiceController extends Controller
{
    /**
     * Public, login-free invoice read.
     *
     * The branch scope is dropped explicitly. BranchContext happens to return
     * null for guests today, but leaning on that would make this endpoint
     * silently depend on behaviour nobody promised.
     */
    public function show($token)
    {
        $order = Order::withoutBranchScope()
            ->with(['customer', 'project', 'items.treatments.service', 'payments' => function ($q) {
                $q->orderBy('date');
            }])
            ->where('invoice_token', $token)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Invoice tidak ditemukan'], 404);
        }

        // Null-safe: the projects row may be gone. Keep the keys either way so
        // the frontend never has to guard.
        $branch = [
            'name' => $order->project?->name,
            // Rendered as a wa.me link on the public page, so prefer the
            // WhatsApp number; fall back to the landline for branches that
            // never set one. ?: not ??, because this legacy schema stores
            // blanks as '' at least as often as NULL.
            'whatsapp' => $order->project?->whatsapp ?: $order->project?->phone,
        ];

        if (! $order->invoice_expires_at || $order->invoice_expires_at < time()) {
            return response()->json([
                'message' => 'Link invoice sudah kedaluwarsa',
                'branch' => $branch,
            ], 410);
        }

        // Same path -> URL rule as OrderController::show (OrderController.php:123-137).
        $items = $order->items->map(function ($item) {
            $photoUrl = null;
            if ($item->photo) {
                if (filter_var($item->photo, FILTER_VALIDATE_URL)) {
                    $photoUrl = $item->photo;
                } else {
                    $photoUrl = asset('storage/'.$item->photo);
                }
            }

            return [
                'name' => $item->name,
                'photo' => $photoUrl,
                'price' => $item->price,
                'discount' => $item->discount,
                'treatments' => $item->treatments->map(function ($treatment) {
                    return [
                        'name' => $treatment->service ? $treatment->service->name : null,
                        'price' => $treatment->price,
                    ];
                })->values(),
            ];
        })->values();

        // Copied verbatim from PaymentController::index (PaymentController.php:77-105)
        // so the public invoice and the payments page can never disagree.
        $dueDate = strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date).' +3 days')));
        $totalPaid = Payment::where('orders_id', $order->id)->sum('nominal');
        $credit = $order->total_price - $totalPaid;
        $paymentStatus = $credit === 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid');

        return response()->json([
            'code' => $order->code,
            'date' => $order->date,
            'due_date' => $dueDate,
            'payment_status' => $paymentStatus,
            'branch' => $branch,
            'customer' => [
                'name' => $order->customer?->name,
                'phone' => $order->customer?->phone,
                'email' => $order->customer?->email,
                'address' => $order->customer?->address,
            ],
            'items' => $items,
            'total_price' => $order->total_price,
            'total_paid' => $totalPaid,
            'credit' => $credit,
            'payments' => $order->payments->map(function ($payment) {
                return [
                    'date' => $payment->date,
                    'nominal' => $payment->nominal,
                    'note' => $payment->note,
                ];
            })->values(),
        ]);
    }
}
