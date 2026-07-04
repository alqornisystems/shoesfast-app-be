<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BroadcastTemplate;
use App\Models\BroadcastSend;
use App\Models\User;
use App\Models\Customer;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BroadcastController extends Controller
{
    protected WhatsAppService $whatsapp;

    public function __construct(WhatsAppService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
    }
    // ==================== TEMPLATES MANAGEMENT ====================

    /**
     * GET /api/broadcasts/templates
     * List all broadcast templates
     */
    public function templates(Request $request): JsonResponse
    {
        $query = BroadcastTemplate::query()
            ->with(['project', 'creator'])
            ->where('is_deleted', 0);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        // Pagination
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $total = $query->count();
        $templates = $query
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($template) {
                return [
                    'id' => $template->id,
                    'name' => $template->name,
                    'content' => $template->content,
                    'variables' => $template->getAvailableVariables(),
                    'branch_name' => $template->project?->name ?? '-',
                    'created_by' => $template->creator?->name ?? '-',
                    'created_at' => $template->created_at,
                    'broadcasts_count' => $template->broadcasts()->where('is_deleted', 0)->count(),
                ];
            });

        return response()->json([
            'data' => $templates,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/broadcasts/templates/{id}
     * Get single template
     */
    public function showTemplate(int $id): JsonResponse
    {
        $template = BroadcastTemplate::with(['project', 'creator'])
            ->where('is_deleted', 0)
            ->findOrFail($id);

        return response()->json([
            'id' => $template->id,
            'name' => $template->name,
            'content' => $template->content,
            'variables' => $template->getAvailableVariables(),
            'projects_id' => $template->projects_id,
            'branch_name' => $template->project?->name ?? '-',
            'created_by' => $template->creator?->name ?? '-',
            'created_at' => $template->created_at,
        ]);
    }

    /**
     * POST /api/broadcasts/templates
     * Create new template
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ]);

        $template = BroadcastTemplate::create([
            'name' => $request->name,
            'content' => $request->content,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Template berhasil dibuat',
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'content' => $template->content,
                'variables' => $template->getAvailableVariables(),
            ],
        ], 201);
    }

    /**
     * PUT /api/broadcasts/templates/{id}
     * Update template
     */
    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ]);

        $template = BroadcastTemplate::where('is_deleted', 0)->findOrFail($id);

        $template->update([
            'name' => $request->name,
            'content' => $request->content,
            'modified_by' => Auth::id(),
            'modified_at' => time(),
        ]);

        return response()->json([
            'message' => 'Template berhasil diperbarui',
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'content' => $template->content,
                'variables' => $template->getAvailableVariables(),
            ],
        ]);
    }

    /**
     * DELETE /api/broadcasts/templates/{id}
     * Soft delete template
     */
    public function destroyTemplate(int $id): JsonResponse
    {
        $template = BroadcastTemplate::where('is_deleted', 0)->findOrFail($id);

        $template->update([
            'is_deleted' => 1,
            'modified_by' => Auth::id(),
            'modified_at' => time(),
        ]);

        return response()->json([
            'message' => 'Template berhasil dihapus',
        ]);
    }

    // ==================== BROADCAST SENDING ====================

    /**
     * GET /api/broadcasts
     * List all broadcast history
     */
    public function index(Request $request): JsonResponse
    {
        $query = BroadcastSend::query()
            ->with(['template', 'project', 'creator'])
            ->where('is_deleted', 0);

        // Filter by date range
        if ($startDate = $request->input('start_date')) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->where('created_at', '<=', $endDate);
        }

        // Pagination
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $total = $query->count();
        $broadcasts = $query
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($broadcast) {
                return [
                    'id' => $broadcast->id,
                    'template_name' => $broadcast->template?->name ?? 'Template dihapus',
                    'branch_name' => $broadcast->project?->name ?? '-',
                    'recipients_count' => $broadcast->recipients_count,
                    'sent_to' => $broadcast->users_id === 'all' ? 'Semua Pengguna' : 'Pengguna Tertentu',
                    'sent_by' => $broadcast->creator?->name ?? '-',
                    'sent_at' => $broadcast->created_at,
                ];
            });

        return response()->json([
            'data' => $broadcasts,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/broadcasts/send
     * Send broadcast message
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'broadcasts_templates_id' => ['required', 'integer', 'exists:broadcasts_templates,id'],
            'recipient_type' => ['required', 'in:all,selected,customers'],
            'recipient_ids' => ['required_if:recipient_type,selected', 'array'],
            'recipient_ids.*' => ['integer'],
        ]);

        $template = BroadcastTemplate::where('is_deleted', 0)
            ->findOrFail($request->broadcasts_templates_id);

        // Determine recipients
        $usersId = 'all';
        $recipients = [];

        if ($request->recipient_type === 'selected') {
            $usersId = json_encode($request->recipient_ids);
            $recipients = User::whereIn('id', $request->recipient_ids)
                ->where('is_deleted', 0)
                ->get(['id', 'name', 'phone']);
        } elseif ($request->recipient_type === 'customers') {
            // Send to customers
            $recipients = Customer::whereIn('id', $request->recipient_ids ?? [])
                ->where('is_deleted', 0)
                ->get(['id', 'name', 'phone']);
            $usersId = json_encode(['type' => 'customers', 'ids' => $request->recipient_ids]);
        } else {
            // All users in current branch
            $recipients = User::where('is_deleted', 0)->get(['id', 'name', 'phone']);
        }

        // Create broadcast record
        $broadcast = BroadcastSend::create([
            'broadcasts_templates_id' => $template->id,
            'users_id' => $usersId,
            'created_by' => Auth::id(),
        ]);

        // Send messages via WhatsApp Cloud API
        $sentCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($recipients as $recipient) {
            if (empty($recipient->phone)) {
                $failedCount++;
                continue;
            }

            // Render message with recipient data
            $message = $template->renderContent([
                'name' => $recipient->name,
                'phone' => $recipient->phone,
                'customer_name' => $recipient->name,
            ]);

            // Send via WhatsApp Cloud API
            $result = $this->whatsapp->sendMessage($recipient->phone, $message);
            $results[] = $result;

            if ($result['status'] === 'success') {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        $statusMessage = "Broadcast berhasil dikirim ke {$sentCount} penerima";
        if ($failedCount > 0) {
            $statusMessage .= ", {$failedCount} gagal";
        }
        if (!$this->whatsapp->isEnabled()) {
            $statusMessage .= " (WhatsApp disabled - messages not actually sent)";
        }

        return response()->json([
            'message' => $statusMessage,
            'data' => [
                'broadcast_id' => $broadcast->id,
                'template_name' => $template->name,
                'recipients_count' => count($recipients),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'whatsapp_enabled' => $this->whatsapp->isEnabled(),
                'details' => $results,
            ],
        ], 201);
    }

    /**
     * GET /api/broadcasts/{id}
     * Get broadcast detail
     */
    public function show(int $id): JsonResponse
    {
        $broadcast = BroadcastSend::with(['template', 'project', 'creator'])
            ->where('is_deleted', 0)
            ->findOrFail($id);

        // Get recipient details
        $recipients = [];
        if ($broadcast->users_id === 'all') {
            $recipients = User::where('projects_id', $broadcast->projects_id)
                ->where('is_deleted', 0)
                ->select('id', 'name', 'phone')
                ->get();
        } else {
            $recipientIds = $broadcast->getRecipientIds();
            if (!empty($recipientIds)) {
                $recipients = User::whereIn('id', $recipientIds)
                    ->where('is_deleted', 0)
                    ->select('id', 'name', 'phone')
                    ->get();
            }
        }

        return response()->json([
            'id' => $broadcast->id,
            'template' => [
                'id' => $broadcast->template?->id,
                'name' => $broadcast->template?->name ?? 'Template dihapus',
                'content' => $broadcast->template?->content,
            ],
            'branch_name' => $broadcast->project?->name ?? '-',
            'recipients_count' => $broadcast->recipients_count,
            'recipients' => $recipients,
            'sent_by' => $broadcast->creator?->name ?? '-',
            'sent_at' => $broadcast->created_at,
        ]);
    }

    /**
     * DELETE /api/broadcasts/{id}
     * Soft delete broadcast
     */
    public function destroy(int $id): JsonResponse
    {
        $broadcast = BroadcastSend::where('is_deleted', 0)->findOrFail($id);

        $broadcast->update([
            'is_deleted' => 1,
            'modified_by' => Auth::id(),
            'modified_at' => time(),
        ]);

        return response()->json([
            'message' => 'Broadcast berhasil dihapus',
        ]);
    }

    // ==================== UTILITY METHODS ====================

    /**
     * GET /api/broadcasts/recipients
     * Get available recipients (users and customers)
     */
    public function recipients(Request $request): JsonResponse
    {
        $type = $request->input('type', 'users'); // users or customers

        if ($type === 'customers') {
            $recipients = Customer::where('is_deleted', 0)
                ->select('id', 'name', 'phone')
                ->orderBy('name')
                ->get();
        } else {
            $recipients = User::where('is_deleted', 0)
                ->select('id', 'name', 'phone', 'projects_id')
                ->with('project:id,name')
                ->orderBy('name')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'branch' => $user->project?->name ?? '-',
                    ];
                });
        }

        return response()->json([
            'data' => $recipients,
        ]);
    }

    /**
     * POST /api/broadcasts/preview
     * Preview rendered message with sample data
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'template_id' => ['required', 'integer', 'exists:broadcasts_templates,id'],
            'sample_data' => ['nullable', 'array'],
        ]);

        $template = BroadcastTemplate::findOrFail($request->template_id);

        // Default sample data
        $sampleData = $request->sample_data ?? [
            'name' => 'John Doe',
            'phone' => '08123456789',
            'order_code' => 'INV202603001',
            'total' => 'Rp 150.000',
            'branch_name' => 'Cabang Utama',
        ];

        $renderedMessage = $template->renderContent($sampleData);

        return response()->json([
            'template' => $template->content,
            'variables' => $template->getAvailableVariables(),
            'sample_data' => $sampleData,
            'rendered_message' => $renderedMessage,
        ]);
    }
}
