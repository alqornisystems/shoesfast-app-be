<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    // GET /api/customer/services
    public function index(): JsonResponse
    {
        // hpp sengaja tidak ikut: itu harga pokok, bukan urusan pelanggan.
        $services = Service::orderBy('name')->get()->map(fn (Service $service) => [
            'id' => $service->id,
            'name' => $service->name,
            'price' => (int) $service->price,
            'estimation' => $service->estimation !== null ? (int) $service->estimation : null,
            'photo' => $service->photo ? $this->photoUrl($service->photo) : null,
            'description' => $service->description,
        ]);

        return response()->json(['data' => $services]);
    }

    // Aturan sama dengan OrderController::show: path relatif jadi URL storage,
    // URL absolut diteruskan apa adanya.
    private function photoUrl(string $photo): string
    {
        return \App\Support\Base64Image::url($photo);
    }
}
