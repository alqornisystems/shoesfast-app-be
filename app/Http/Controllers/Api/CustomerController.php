<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 25);
        $search = $request->get('search', '');
        $isMember = $request->get('is_member');

        $query = Customer::with('projects');

        // Filter berdasarkan cabang:
        // - Customer yang terdaftar di cabang ini, ATAU
        // - Customer yang tidak punya cabang sama sekali (tampil di semua cabang)
        $query->where(function ($q) {
            $q->whereHas('projects', function ($projectQuery) {
                $projectQuery->where('projects.id', 1);
            })->orWhereDoesntHave('projects'); // Tidak punya cabang = tampil di semua
        });

        // Filter member jika diminta
        if ($isMember !== null) {
            $query->where('is_member', (int) $isMember);
        }

        // Search by name, phone, email, address, or project name
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('hobby', 'like', "%{$search}%")
                  ->orWhere('favorite_food', 'like', "%{$search}%")
                  ->orWhere('behavior', 'like', "%{$search}%")
                  ->orWhereHas('projects', function ($projectQuery) use ($search) {
                      $projectQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $customers = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Transform to include projects array
        $customers->getCollection()->transform(function ($customer) {
            $customer->project_names = $customer->projects->pluck('name')->toArray();
            $customer->project_ids = $customer->projects->pluck('id')->toArray();
            return $customer;
        });

        return response()->json($customers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string',
            'email' => 'nullable|email|max:200',
            'instagram' => 'nullable|string|max:100',
            'photo' => 'nullable|string',
            'maps' => 'nullable|string',
            'date_of_birth' => 'nullable|integer',
            'hobby' => 'nullable|string',
            'favorite_food' => 'nullable|string',
            'behavior' => 'nullable|string',
            'is_member' => 'nullable|boolean',
            'member_code' => 'nullable|string|max:50|unique:customers,member_code',
            'member_since' => 'nullable|date',
            'points' => 'nullable|integer|min:0',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'exists:projects,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $projectIds = $data['project_ids'] ?? [];
        unset($data['project_ids']);

        // Normalize phone number
        if (isset($data['phone'])) {
            $data['phone'] = $this->normalizePhone($data['phone']);
        }

        $data['projects_id'] = 1;
        $data['created_by'] = auth()->id();

        $customer = Customer::create($data);

        // Sync projects (many-to-many)
        if (!empty($projectIds)) {
            $customer->projects()->sync($projectIds);
        }

        $customer->load('projects');
        $customer->project_names = $customer->projects->pluck('name')->toArray();
        $customer->project_ids = $customer->projects->pluck('id')->toArray();

        return response()->json(['data' => $customer]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $customer = Customer::with('projects')->where('projects_id', 1)->findOrFail($id);

        $customer->project_names = $customer->projects->pluck('name')->toArray();
        $customer->project_ids = $customer->projects->pluck('id')->toArray();

        return response()->json(['data' => $customer]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $customer = Customer::where('projects_id', 1)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string',
            'email' => 'nullable|email|max:200',
            'instagram' => 'nullable|string|max:100',
            'photo' => 'nullable|string',
            'maps' => 'nullable|string',
            'date_of_birth' => 'nullable|integer',
            'hobby' => 'nullable|string',
            'favorite_food' => 'nullable|string',
            'behavior' => 'nullable|string',
            'is_member' => 'nullable|boolean',
            'member_code' => 'nullable|string|max:50|unique:customers,member_code,' . $id,
            'member_since' => 'nullable|date',
            'points' => 'nullable|integer|min:0',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'exists:projects,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $projectIds = $data['project_ids'] ?? [];
        unset($data['project_ids']);

        // Normalize phone number
        if (isset($data['phone'])) {
            $data['phone'] = $this->normalizePhone($data['phone']);
        }

        $data['modified_by'] = auth()->id();

        $customer->update($data);

        // Sync projects (many-to-many)
        if (!empty($projectIds)) {
            $customer->projects()->sync($projectIds);
        } else {
            $customer->projects()->detach();
        }

        $customer->load('projects');
        $customer->project_names = $customer->projects->pluck('name')->toArray();
        $customer->project_ids = $customer->projects->pluck('id')->toArray();

        return response()->json(['data' => $customer]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $customer = Customer::where('projects_id', 1)->findOrFail($id);

        $customer->update([
            'is_deleted' => 1,
            'modified_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    /**
     * Normalize phone number (remove 0 or 62 prefix)
     */
    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $normalized = preg_replace('/\D/', '', $phone);

        if (str_starts_with($normalized, '62')) {
            $normalized = substr($normalized, 2);
        } elseif (str_starts_with($normalized, '0')) {
            $normalized = substr($normalized, 1);
        }

        return $normalized ?: null;
    }
}
