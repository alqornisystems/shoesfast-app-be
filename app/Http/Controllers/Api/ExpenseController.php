<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\ReportCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::with('project')->orderBy('date', 'DESC');

        if ($request->has('category') && $request->category !== '') {
            $query->where('category', $request->category);
        }

        if ($request->has('search') && $request->search !== '') {
            $query->where('note', 'LIKE', "%{$request->search}%");
        }

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'note' => 'required|string',
            'nominal' => 'required|integer|min:0',
            'category' => 'required|in:other,oprational',
            'photo' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $photoPath = null;
            if (!empty($validated['photo'])) {
                $photoPath = $this->uploadBase64Image($validated['photo'], 'expense-' . time());
            }

            $expense = Expense::create([
                'date' => strtotime($validated['date']),
                'note' => $validated['note'],
                'nominal' => $validated['nominal'],
                'category' => $validated['category'],
                'photo' => $photoPath,
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'expenses',
                'profit-loss',
                'cash-flow',
            ]);

            return response()->json(['message' => 'Pengeluaran berhasil ditambahkan', 'data' => $expense], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal menambahkan pengeluaran', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);
        $validated = $request->validate([
            'date' => 'sometimes|date',
            'note' => 'sometimes|string',
            'nominal' => 'sometimes|integer|min:0',
            'category' => 'sometimes|in:other,oprational',
            'photo' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['photo']) && $validated['photo']) {
                if ($expense->photo && Storage::exists($expense->photo)) {
                    Storage::delete($expense->photo);
                }
                $validated['photo'] = $this->uploadBase64Image($validated['photo'], 'expense-' . time());
            }

            if (isset($validated['date'])) {
                $validated['date'] = strtotime($validated['date']);
            }

            $validated['modified_by'] = auth()->id();
            $expense->update($validated);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'expenses',
                'profit-loss',
                'cash-flow',
            ]);

            return response()->json(['message' => 'Pengeluaran berhasil diupdate', 'data' => $expense]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal mengupdate pengeluaran', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $expense = Expense::findOrFail($id);
        $expense->update(['is_deleted' => 1, 'modified_by' => auth()->id()]);

        // Invalidate affected report caches
        ReportCacheService::invalidate([
            'expenses',
            'profit-loss',
            'cash-flow',
        ]);

        return response()->json(['message' => 'Pengeluaran berhasil dihapus']);
    }

    private function uploadBase64Image($base64String, $filename)
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $base64String = substr($base64String, strpos($base64String, ',') + 1);
            $type = strtolower($type[1]);
            $imageData = base64_decode(str_replace(' ', '+', $base64String));
            if ($imageData === false) throw new \Exception('base64_decode failed');

            $path = 'expenses/' . $filename . '.' . $type;
            Storage::disk('public')->put($path, $imageData);
            return $path;
        }
        throw new \Exception('Invalid image format');
    }
}
