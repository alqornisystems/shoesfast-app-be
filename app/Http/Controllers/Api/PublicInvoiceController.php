<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;

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
            ->with(['customer', 'project'])
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

        return response()->json([
            'code' => $order->code,
            'date' => $order->date,
            'branch' => $branch,
            'customer' => [
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
                'address' => $order->customer->address,
            ],
        ]);
    }
}
