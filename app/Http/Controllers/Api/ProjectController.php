<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    // GET /api/projects
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 25);

        $projects = Project::orderBy('name')->paginate($perPage);

        return response()->json($projects);
    }

    // POST /api/projects
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:100'],
            'full_address'    => ['nullable', 'string'],
            'latitude'        => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'       => ['nullable', 'numeric', 'between:-180,180'],
            'maps'            => ['nullable', 'string'],
            'phone'           => ['nullable', 'string', 'max:25'],
            'whatsapp'        => ['nullable', 'string', 'max:25'],
            'email'           => ['nullable', 'email', 'max:50'],
            'logo'            => ['nullable', 'string'],
            'instagram'       => ['nullable', 'string', 'max:100'],
            'facebook'        => ['nullable', 'string', 'max:100'],
            'tiktok'          => ['nullable', 'string', 'max:100'],
            'website'         => ['nullable', 'string', 'max:100'],
        ]);

        $project = Project::create([
            'name'            => $validated['name'],
            'full_address'    => $validated['full_address'] ?? null,
            'latitude'        => $validated['latitude'] ?? null,
            'longitude'       => $validated['longitude'] ?? null,
            'maps'            => $validated['maps'] ?? null,
            'phone'           => $validated['phone'] ?? null,
            'whatsapp'        => $validated['whatsapp'] ?? null,
            'email'           => $validated['email'] ?? null,
            'logo'            => $validated['logo'] ?? null,
            'instagram'       => $validated['instagram'] ?? null,
            'facebook'        => $validated['facebook'] ?? null,
            'tiktok'          => $validated['tiktok'] ?? null,
            'website'         => $validated['website'] ?? null,
            'created_by'      => auth()->id() ?? 1,
            'modified_by'     => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'Cabang berhasil ditambahkan.',
            'data'    => $project,
        ], 201);
    }

    // GET /api/projects/{project}
    public function show(Project $project): JsonResponse
    {
        return response()->json(['data' => $project]);
    }

    // PUT /api/projects/{project}
    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:100'],
            'full_address'    => ['nullable', 'string'],
            'latitude'        => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'       => ['nullable', 'numeric', 'between:-180,180'],
            'maps'            => ['nullable', 'string'],
            'phone'           => ['nullable', 'string', 'max:25'],
            'whatsapp'        => ['nullable', 'string', 'max:25'],
            'email'           => ['nullable', 'email', 'max:50'],
            'logo'            => ['nullable', 'string'],
            'instagram'       => ['nullable', 'string', 'max:100'],
            'facebook'        => ['nullable', 'string', 'max:100'],
            'tiktok'          => ['nullable', 'string', 'max:100'],
            'website'         => ['nullable', 'string', 'max:100'],
        ]);

        $project->update([
            'name'            => $validated['name'],
            'full_address'    => $validated['full_address'] ?? null,
            'latitude'        => $validated['latitude'] ?? null,
            'longitude'       => $validated['longitude'] ?? null,
            'maps'            => $validated['maps'] ?? null,
            'phone'           => $validated['phone'] ?? null,
            'whatsapp'        => $validated['whatsapp'] ?? null,
            'email'           => $validated['email'] ?? null,
            'logo'            => $validated['logo'] ?? null,
            'instagram'       => $validated['instagram'] ?? null,
            'facebook'        => $validated['facebook'] ?? null,
            'tiktok'          => $validated['tiktok'] ?? null,
            'website'         => $validated['website'] ?? null,
            'modified_by'     => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'Cabang berhasil diperbarui.',
            'data'    => $project->fresh(),
        ]);
    }

    // DELETE /api/projects/{project}
    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json(['message' => 'Cabang berhasil dihapus.']);
    }
}
