<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceController extends Controller
{
    /**
     * Display a listing of services
     */
    public function index(Request $request)
    {
        $query = Service::query();

        // Search functionality
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->get('per_page', 25);
        $services = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($services);
    }

    /**
     * Store a newly created service
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'hpp' => 'required|integer|min:0',
            'estimation' => 'required|integer|min:0',
            'photo' => 'nullable|string',
            'description' => 'nullable|string',
        ], [
            'name.required' => 'Nama layanan wajib diisi.',
            'price.required' => 'Harga wajib diisi.',
            'price.integer' => 'Harga harus berupa angka.',
            'hpp.required' => 'HPP wajib diisi.',
            'hpp.integer' => 'HPP harus berupa angka.',
            'estimation.required' => 'Estimasi pengerjaan wajib diisi.',
            'estimation.integer' => 'Estimasi harus berupa angka.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $service = Service::create($validator->validated());

        return response()->json([
            'message' => 'Service created successfully',
            'data' => $service
        ], 201);
    }

    /**
     * Display the specified service
     */
    public function show($id)
    {
        $service = Service::findOrFail($id);
        return response()->json(['data' => $service]);
    }

    /**
     * Update the specified service
     */
    public function update(Request $request, $id)
    {
        $service = Service::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'hpp' => 'required|integer|min:0',
            'estimation' => 'required|integer|min:0',
            'photo' => 'nullable|string',
            'description' => 'nullable|string',
        ], [
            'name.required' => 'Nama layanan wajib diisi.',
            'price.required' => 'Harga wajib diisi.',
            'price.integer' => 'Harga harus berupa angka.',
            'hpp.required' => 'HPP wajib diisi.',
            'hpp.integer' => 'HPP harus berupa angka.',
            'estimation.required' => 'Estimasi pengerjaan wajib diisi.',
            'estimation.integer' => 'Estimasi harus berupa angka.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $service->update($validator->validated());

        return response()->json([
            'message' => 'Service updated successfully',
            'data' => $service
        ]);
    }

    /**
     * Soft delete the specified service
     */
    public function destroy($id)
    {
        $service = Service::findOrFail($id);
        $service->delete(); // Soft delete via is_deleted = 1

        return response()->json([
            'message' => 'Service deleted successfully'
        ]);
    }
}
