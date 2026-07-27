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

        return response()->json([
            'code' => $order->code,
            'date' => $order->date,
            'branch' => [
                'name' => $order->project->name,
                'whatsapp' => $order->project->whatsapp,
            ],
            'customer' => [
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
                'address' => $order->customer->address,
            ],
        ]);
    }
}
