<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseOperational;
use App\Services\ReportCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseOperationalController extends Controller
{
    public function index(Request $request)
    {
        $query = ExpenseOperational::with('project')->orderBy('id', 'DESC');

        if ($request->has('search') && $request->search !== '') {
            $query->where(function($q) use ($request) {
                $q->where('name', 'LIKE', "%{$request->search}%")
                  ->orWhere('note', 'LIKE', "%{$request->search}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'note' => 'nullable|string',
            'cost_basis' => 'nullable|string|max:100',
            'nominal' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            $expense = ExpenseOperational::create([
                'name' => $validated['name'],
                'note' => $validated['note'] ?? null,
                'cost_basis' => $validated['cost_basis'] ?? null,
                'nominal' => $validated['nominal'],
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'expenses',
                'profit-loss',
                'cash-flow',
            ]);

            return response()->json(['message' => 'Pengeluaran operasional berhasil ditambahkan', 'data' => $expense], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal menambahkan pengeluaran', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $expense = ExpenseOperational::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'note' => 'nullable|string',
            'cost_basis' => 'nullable|string|max:100',
            'nominal' => 'sometimes|integer|min:0',
        ]);

        $validated['modified_by'] = auth()->id();
        $expense->update($validated);

        // Invalidate affected report caches
        ReportCacheService::invalidate([
            'expenses',
            'profit-loss',
            'cash-flow',
        ]);

        return response()->json(['message' => 'Pengeluaran operasional berhasil diupdate', 'data' => $expense]);
    }

    public function destroy($id)
    {
        $expense = ExpenseOperational::findOrFail($id);
        $expense->update(['is_deleted' => 1, 'modified_by' => auth()->id()]);

        // Invalidate affected report caches
        ReportCacheService::invalidate([
            'expenses',
            'profit-loss',
            'cash-flow',
        ]);

        return response()->json(['message' => 'Pengeluaran operasional berhasil dihapus']);
    }
}
