<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailyNoteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\ExpenseOperationalController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PartnershipController;
use App\Http\Controllers\Api\PartnershipTreatmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SendController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceHppController;
use App\Http\Controllers\Api\TreatmentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

// Public Routes (No Authentication Required)
Route::prefix('auth')->group(function () {
    // Rate-limit: maks 6 percobaan login per menit per IP
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
});

// WAHA (WhatsApp HTTP API) Webhook (No Authentication Required, diverifikasi HMAC)
Route::post('webhook', [WebhookController::class, 'whatsapp']);

// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('switch-branch', [AuthController::class, 'switchBranch']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('change-password', [AuthController::class, 'changePassword']);
    });

    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::apiResource('roles', RoleController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('services', ServiceController::class);

    // Service HPP
    Route::prefix('services/{serviceId}/hpp')->group(function () {
        Route::get('/', [ServiceHppController::class, 'index']);
        Route::post('/', [ServiceHppController::class, 'store']);
        Route::post('/batch', [ServiceHppController::class, 'batchSave']);
        Route::put('/{id}', [ServiceHppController::class, 'update']);
        Route::delete('/{id}', [ServiceHppController::class, 'destroy']);
    });

    // Orders - custom routes before apiResource
    Route::get('orders/search/customers', [OrderController::class, 'searchCustomers']);
    Route::get('orders/search/services', [OrderController::class, 'searchServices']);
    Route::get('orders/available-pickup', [OrderController::class, 'getAvailablePickupOrders']);
    Route::get('orders/{orderId}/items', [OrderController::class, 'getItems']);
    Route::post('orders/{orderId}/items', [OrderController::class, 'saveItem']);
    Route::delete('orders/{orderId}/items/{itemId}', [OrderController::class, 'removeItem']);
    Route::apiResource('orders', OrderController::class);

    // Sends (Delivery & Pickup) - custom routes before apiResource
    Route::get('sends/pickup-waiting-list', [SendController::class, 'pickupWaitingList']);
    Route::get('sends/delivery-waiting-list', [SendController::class, 'deliveryWaitingList']);
    Route::get('sends/in-progress', [SendController::class, 'inProgress']);
    Route::get('sends/history', [SendController::class, 'history']);
    Route::get('sends/available-pickup-orders', [SendController::class, 'getAvailablePickupOrders']);
    Route::get('sends/available-delivery-items', [SendController::class, 'getAvailableDeliveryItems']);
    Route::get('sends/available-couriers', [SendController::class, 'getAvailableCouriers']);
    Route::post('sends/mark-completed', [SendController::class, 'markAsCompleted']);
    Route::apiResource('sends', SendController::class);

    // Treatments (Waiting List & Work Progress)
    Route::get('treatments', [TreatmentController::class, 'index']);
    Route::post('treatments/assign', [TreatmentController::class, 'assignToUser']);
    Route::post('treatments/force-complete', [TreatmentController::class, 'forceComplete']);
    Route::put('treatments/{id}/status', [TreatmentController::class, 'updateStatus']);
    Route::put('treatments/{id}/update', [TreatmentController::class, 'update']);
    Route::get('treatments/available-technicians', [TreatmentController::class, 'getAvailableTechnicians']);

    // Payments
    Route::get('payments', [PaymentController::class, 'index']);
    Route::post('payments', [PaymentController::class, 'store']);
    Route::get('payments/order/{orderId}', [PaymentController::class, 'getByOrder']);
    Route::get('payments/unpaid-orders', [PaymentController::class, 'getUnpaidOrders']);
    Route::delete('payments/{id}', [PaymentController::class, 'destroy']);

    // Expenses
    Route::apiResource('expenses', ExpenseController::class);
    Route::apiResource('expense-operationals', ExpenseOperationalController::class);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('sales', [ReportController::class, 'sales']);
        Route::get('payments', [ReportController::class, 'payments']);
        Route::get('receivables', [ReportController::class, 'receivables']);
        Route::get('orders', [ReportController::class, 'orders']);
        Route::get('expenses', [ReportController::class, 'expenses']);
        Route::get('hpp', [ReportController::class, 'hpp']);
        Route::get('profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('cash-flow', [ReportController::class, 'cashFlow']);
        Route::get('treatments', [ReportController::class, 'treatments']);
        Route::get('customers', [ReportController::class, 'customers']);
        Route::get('top-services', [ReportController::class, 'topServices']);
        Route::get('google-ads', [ReportController::class, 'googleAds']);
        Route::get('meta-ads', [ReportController::class, 'metaAds']);
        Route::get('attendance', [ReportController::class, 'attendance']);
        Route::get('daily-notes', [ReportController::class, 'dailyNotes']);
        Route::get('daily-notes-matrix', [ReportController::class, 'dailyNotesMatrix']);
        Route::get('performance', [ReportController::class, 'performance']);
    });

    // Attendance
    Route::get('attendances/today', [AttendanceController::class, 'today']);
    Route::post('attendances/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendances/clock-out', [AttendanceController::class, 'clockOut']);
    Route::get('attendances', [AttendanceController::class, 'index']);

    // Absence Requests
    Route::get('absences', [AttendanceController::class, 'absences']);
    Route::post('absences', [AttendanceController::class, 'storeAbsence']);
    Route::put('absences/{id}/approve', [AttendanceController::class, 'approveAbsence']);
    Route::put('absences/{id}/reject', [AttendanceController::class, 'rejectAbsence']);
    Route::delete('absences/{id}', [AttendanceController::class, 'deleteAbsence']);

    // Daily Notes (Catatan Harian)
    Route::get('daily-notes/available-users', [DailyNoteController::class, 'availableUsers']);
    Route::get('daily-notes/search-users', [DailyNoteController::class, 'searchUsers']);
    Route::get('daily-notes/today', [DailyNoteController::class, 'today']);
    Route::put('daily-notes/{id}/toggle-status', [DailyNoteController::class, 'toggleStatus']);
    Route::apiResource('daily-notes', DailyNoteController::class);

    // Holidays (Master Kalender Libur)
    Route::apiResource('holidays', HolidayController::class);

    // Partnerships (Mitra Kerja)
    Route::apiResource('partnerships', PartnershipController::class);

    // Partnership Treatments (Pengerjaan Mitra) - custom routes for partnerships to manage their treatments
    Route::prefix('partnerships/{partnershipId}/treatments')->group(function () {
        Route::get('/', [PartnershipTreatmentController::class, 'myTreatments']);
        Route::get('/statistics', [PartnershipTreatmentController::class, 'statistics']);
        Route::get('/{treatmentId}', [PartnershipTreatmentController::class, 'show']);
        Route::put('/{treatmentId}/status', [PartnershipTreatmentController::class, 'updateStatus']);
    });

    // WhatsApp (WAHA) connection management — scan QR & session control
    Route::prefix('whatsapp')->group(function () {
        Route::get('status', [WhatsAppController::class, 'status']);
        Route::get('qr', [WhatsAppController::class, 'qr']);
        Route::get('settings', [WhatsAppController::class, 'settings']);
        Route::put('settings', [WhatsAppController::class, 'updateSettings']);
        Route::post('start', [WhatsAppController::class, 'start']);
        Route::post('stop', [WhatsAppController::class, 'stop']);
        Route::post('restart', [WhatsAppController::class, 'restart']);
        Route::post('logout', [WhatsAppController::class, 'logout']);
    });

    // Broadcasts (WhatsApp/SMS Broadcasting)
    Route::prefix('broadcasts')->group(function () {
        // Templates Management
        Route::get('templates', [BroadcastController::class, 'templates']);
        Route::get('templates/{id}', [BroadcastController::class, 'showTemplate']);
        Route::post('templates', [BroadcastController::class, 'storeTemplate']);
        Route::put('templates/{id}', [BroadcastController::class, 'updateTemplate']);
        Route::delete('templates/{id}', [BroadcastController::class, 'destroyTemplate']);

        // Broadcast Sending
        Route::post('send', [BroadcastController::class, 'send']);
        Route::get('recipients', [BroadcastController::class, 'recipients']);
        Route::post('preview', [BroadcastController::class, 'preview']);

        // Broadcast History
        Route::get('/', [BroadcastController::class, 'index']);
        Route::get('{id}', [BroadcastController::class, 'show']);
        Route::delete('{id}', [BroadcastController::class, 'destroy']);
    });
});
