<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;
use App\Http\Controllers\Api\MaintenanceController as ApiMaintenanceController;

/*
|--------------------------------------------------------------------------
| API Routes — TwinBackend
|--------------------------------------------------------------------------
*/

// Single health-check endpoint.
Route::get('/ping', fn () => response()->json(['status' => 'ok', 'version' => 'v1']));

// ── Maintenance status (public — polled by frontend) ─────────────────────────
Route::get('/maintenance', [ApiMaintenanceController::class, 'status']);

// ── API monitoring — admin only ───────────────────────────────────────────────
Route::get('/apistatus', [Api\ApiStatusController::class, 'status']); // public health check
Route::middleware(['auth:sanctum', 'company:telcovantage'])->group(function () {
    Route::get('/apiconsumption',          [Api\ApiStatusController::class, 'consumption']);
    Route::delete('/apiconsumption/reset', [Api\ApiStatusController::class, 'reset']);

    // Maintenance toggle — admin only
    Route::post('/admin/maintenance',            [Api\MaintenanceController::class, 'toggle']);
    Route::delete('/admin/maintenance/lift-all', [Api\MaintenanceController::class, 'liftAll']);
});

// ── Public file serving (no auth — images embedded in reports) ────────────────
Route::get('/files/{path}', function (string $path) {
    $candidates = array_unique([
        $path,
        rawurldecode($path),
        urldecode($path),
        ltrim(rawurldecode($path), '/\\'),
    ]);

    foreach ($candidates as $candidate) {
        $candidate = str_replace('\\', '/', $candidate);
        if (str_contains($candidate, '..')) abort(403);

        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($candidate)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->response($candidate);
        }

        $absolute = storage_path('app/public/' . $candidate);
        if (is_file($absolute)) {
            return response()->file($absolute);
        }
    }

    abort(404);
})->where('path', '.*');

// ── AsBuilt IQ — API-key only, no user credentials ───────────────────────────
Route::middleware(['asbuilt.key'])->prefix('asbuilt')->group(function () {
    // sites = skycable_areas  (AsBuilt IQ terminology)
    Route::get('sites',                    [Api\Skycable\AsBuiltController::class, 'sites']);
    Route::get('sites/{areaId}/nodes',     [Api\Skycable\AsBuiltController::class, 'nodesBySite']);

    // Import (raw JSON body or .json file upload)
    Route::post('import',                  [Api\Skycable\AsBuiltController::class, 'import']);

    // Sequence-first import — spans use from_sequence/to_sequence, no pole_code disambiguation needed
    Route::post('import-by-sequence',      [Api\Skycable\AsBuiltController::class, 'importBySequence']);

    // Read back node state after import
    Route::get('node/{nodeId}',            [Api\Skycable\AsBuiltController::class, 'node']);

    // Team & subcontractor lookup — for assignment during import
    Route::get('subcontractors',           [Api\Skycable\AsBuiltController::class, 'subcontractors']);
    Route::get('teams',                    [Api\Skycable\AsBuiltController::class, 'teams']);

    // Backfill span_codes for existing spans that were imported before span_code was added
    Route::post('backfill-span-codes',     [Api\Skycable\AsBuiltController::class, 'backfillSpanCodes']);
});

// ── Field submission (any authenticated company user) ─────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('teardown-logs', [Api\Skycable\TeardownController::class, 'storeDirect']);
    Route::get('nodes/map-pins', [Api\Skycable\NodeController::class, 'mapPins']);
    
    // Unified Teardown Image Uploads
    Route::post('teardown/upload-image',                      [Api\TeardownImageController::class, 'upload']);
    Route::get('teardown/node-images/{nodeId}',               [Api\TeardownImageController::class, 'fetchByNode']);
    Route::get('teardown/report-images/{reportId}',           [Api\TeardownImageController::class, 'fetchByReport']);
    Route::get('teardown/pole-images/{poleId}',               [Api\TeardownImageController::class, 'fetchByPole']);
    Route::patch('teardown/images/{image}/lock',              [Api\TeardownImageController::class, 'lock']);
    Route::patch('teardown/images/{image}/unlock',            [Api\TeardownImageController::class, 'unlock']);
});

// ── TelcoVantage Admin ────────────────────────────────────────────────────────

Route::prefix('admin/auth')->group(function () {
    Route::post('login',           [Api\Admin\AuthController::class, 'login']);
    Route::post('forgot-password', [Api\Admin\AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [Api\Admin\AuthController::class, 'resetPassword']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'company:telcovantage'])->group(function () {
    Route::post('auth/logout',          [Api\Admin\AuthController::class, 'logout']);
    Route::get('auth/me',               [Api\Admin\AuthController::class, 'me']);
    Route::put('auth/profile',          [Api\Admin\AuthController::class, 'updateProfile']);
    Route::post('auth/change-password', [Api\Admin\AuthController::class, 'changePassword']);

    // Users (cross-company)
    Route::get('users',                          [Api\Admin\UserController::class, 'index']);
    Route::post('users',                         [Api\Admin\UserController::class, 'store']);
    Route::get('users/{user}',                   [Api\Admin\UserController::class, 'show']);
    Route::put('users/{user}',                   [Api\Admin\UserController::class, 'update']);
    Route::delete('users/{user}',                [Api\Admin\UserController::class, 'destroy']);
    Route::post('users/{user}/reset-password',   [Api\Admin\UserController::class, 'resetPassword']);
    Route::put('users/{user}/status',            [Api\Admin\UserController::class, 'updateStatus']);
    Route::post('users/{id}/restore',            [Api\Admin\UserController::class, 'restore']);

    // Subcontractors
    Route::apiResource('subcontractors', Api\Admin\SubcontractorController::class);

    // Teams
    Route::get('teams',                         [Api\Admin\TeamController::class, 'index']);
    Route::post('teams',                        [Api\Admin\TeamController::class, 'store']);
    Route::get('teams/{team}',                  [Api\Admin\TeamController::class, 'show']);
    Route::put('teams/{team}',                  [Api\Admin\TeamController::class, 'update']);
    Route::delete('teams/{team}',               [Api\Admin\TeamController::class, 'destroy']);
    Route::post('teams/{team}/members',         [Api\Admin\TeamController::class, 'addMember']);
    Route::delete('teams/{team}/members',       [Api\Admin\TeamController::class, 'removeMember']);

    // Active users, audit, support
    Route::get('active-users',                  [Api\ActiveUserController::class, 'index']);
    Route::get('audit-logs',                    [Api\AuditLogController::class, 'index']);
    Route::get('audit-logs/{auditLog}',         [Api\AuditLogController::class, 'show']);
    Route::get('support/tickets',               [Api\SupportTicketController::class, 'index']);
    Route::get('support/tickets/{supportTicket}',[Api\SupportTicketController::class, 'show']);
    Route::put('support/tickets/{supportTicket}/assign', [Api\SupportTicketController::class, 'assign']);
    Route::put('support/tickets/{supportTicket}/status', [Api\SupportTicketController::class, 'updateStatus']);
    Route::post('support/tickets/{supportTicket}/reply', [Api\SupportTicketController::class, 'reply']);
    Route::get('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'show']);
    Route::post('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'startOrJoin']);
    Route::put('support/ticket-sessions/{supportTicketSession}/end', [Api\SupportTicketSessionController::class, 'end']);

    // PSGC
    Route::get('locations/regions',   [Api\PsgcController::class, 'regions']);
    Route::get('locations/provinces', [Api\PsgcController::class, 'provinces']);
    Route::get('locations/cities',    [Api\PsgcController::class, 'cities']);
    Route::get('locations/barangays', [Api\PsgcController::class, 'barangays']);
});

// ── Skycable ──────────────────────────────────────────────────────────────────

Route::prefix('skycable/auth')->group(function () {
    Route::post('login',           [Api\Skycable\AuthController::class, 'login']);
    Route::post('forgot-password', [Api\Skycable\AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [Api\Skycable\AuthController::class, 'resetPassword']);
});

Route::prefix('skycable')->middleware(['auth:sanctum', 'company:skycable'])->group(function () {
    Route::post('auth/logout',          [Api\Skycable\AuthController::class, 'logout']);
    Route::get('auth/me',               [Api\Skycable\AuthController::class, 'me']);
    Route::put('auth/profile',          [Api\Skycable\AuthController::class, 'updateProfile']);
    Route::post('auth/change-password', [Api\Skycable\AuthController::class, 'changePassword']);

    // PSGC
    Route::get('locations/regions',   [Api\PsgcController::class, 'regions']);
    Route::get('locations/provinces', [Api\PsgcController::class, 'provinces']);
    Route::get('locations/cities',    [Api\PsgcController::class, 'cities']);
    Route::get('locations/barangays', [Api\PsgcController::class, 'barangays']);

    // Areas
    Route::get('areas',           [Api\Skycable\AreaController::class, 'index']);
    Route::post('areas',          [Api\Skycable\AreaController::class, 'store']);
    Route::get('areas/{area}',    [Api\Skycable\AreaController::class, 'show']);
    Route::put('areas/{area}',    [Api\Skycable\AreaController::class, 'update']);
    Route::delete('areas/{area}', [Api\Skycable\AreaController::class, 'destroy']);

    // Sites
    Route::get('sites',           [Api\Skycable\SiteController::class, 'index']);
    Route::post('sites',          [Api\Skycable\SiteController::class, 'store']);
    Route::get('sites/{site}',    [Api\Skycable\SiteController::class, 'show']);
    Route::put('sites/{site}',    [Api\Skycable\SiteController::class, 'update']);
    Route::delete('sites/{site}', [Api\Skycable\SiteController::class, 'destroy']);

    // Nodes
    Route::get('nodes',                    [Api\Skycable\NodeController::class, 'index']);
    Route::get('nodes/map-pins',           [Api\Skycable\NodeController::class, 'mapPins']);
    Route::post('nodes',                   [Api\Skycable\NodeController::class, 'store']);
    Route::get('nodes/{node}',             [Api\Skycable\NodeController::class, 'show']);
    Route::put('nodes/{node}',             [Api\Skycable\NodeController::class, 'update']);
    Route::patch('nodes/{node}',           [Api\Skycable\NodeController::class, 'update']);
    Route::delete('nodes/{node}',          [Api\Skycable\NodeController::class, 'destroy']);
    Route::post('nodes/{node}/import-poles',[Api\Skycable\NodeController::class, 'importJson']);

    // Poles
    Route::get('nodes/{node}/poles',                    [Api\Skycable\PoleController::class, 'index']);
    Route::put('nodes/{node}/poles/{skycablePole}',     [Api\Skycable\PoleController::class, 'updatePole']);
    Route::patch('nodes/{node}/poles/sync',             [Api\Skycable\PoleController::class, 'syncPole']);
    Route::post('poles/{pole}/report',                  [Api\Skycable\PoleController::class, 'storeReport']);
    Route::get('pole-reports',                          [Api\Skycable\PoleController::class, 'listReports']);
    Route::get('pole-reports/{poleReport}',             [Api\Skycable\PoleController::class, 'showReport']);
    Route::get('nodes/{node}/pole-photos',              [Api\Skycable\NodeController::class, 'polePhotos']);
    Route::post('poles',             [Api\Skycable\PoleController::class, 'store']);
    Route::get('poles/code/{code}',  [Api\Skycable\PoleController::class, 'showByCode']);
    Route::get('poles/map',          [Api\Skycable\PoleController::class, 'mapPins']);
    Route::get('poles/all',          [Api\Skycable\PoleController::class, 'allPoles']);
    Route::get('poles/{pole}',       [Api\Skycable\PoleController::class, 'show']);
    Route::put('poles/{pole}',       [Api\Skycable\PoleController::class, 'update']);
    Route::delete('poles/{pole}',      [Api\Skycable\PoleController::class, 'destroy']);
    Route::get('poles/{pole}/slots',   [Api\Skycable\PoleController::class, 'slots']);
    Route::post('poles/{pole}/slots',  [Api\Skycable\PoleController::class, 'addSlot']);
    Route::post('poles/{pole}/gps',    [Api\Skycable\PoleController::class, 'updateGps']);

    // Pole teardown logs (start / finish / status per pole)
    Route::post('pole-teardown-logs',              [Api\Skycable\PoleTeardownLogController::class, 'upsert']);
    Route::get('pole-teardown-logs/node/{nodeId}', [Api\Skycable\PoleTeardownLogController::class, 'byNode']);
    Route::get('pole-teardown-logs/pole/{poleId}', [Api\Skycable\PoleTeardownLogController::class, 'byPole']);

    // Spans
    Route::get('spans/stats',                      [Api\Skycable\SpanController::class, 'stats']);
    Route::get('spans',                            [Api\Skycable\SpanController::class, 'index']);
    Route::post('spans',                           [Api\Skycable\SpanController::class, 'store']);
    Route::get('spans/{span}',                     [Api\Skycable\SpanController::class, 'show']);
    Route::put('spans/{span}',                     [Api\Skycable\SpanController::class, 'update']);
    Route::patch('spans/{span}',                   [Api\Skycable\SpanController::class, 'update']);
    Route::patch('spans/{span}/status',            [Api\Skycable\SpanController::class, 'updateStatus']);
    Route::delete('spans/{span}',                  [Api\Skycable\SpanController::class, 'destroy']);
    Route::put('spans/{span}/components',          [Api\Skycable\SpanController::class, 'updateComponents']);
    Route::post('spans/{span}/split',              [Api\Skycable\SpanController::class, 'split']);
    Route::get('nodes/{node}/spans',               [Api\Skycable\SpanController::class, 'byNode']);

    // Teardown Reports
    Route::get('teardowns',                        [Api\Skycable\TeardownController::class, 'index']);
    Route::post('teardowns/start',                 [Api\Skycable\TeardownController::class, 'start']);
    Route::get('teardowns/{report}',               [Api\Skycable\TeardownController::class, 'show']);
    Route::post('teardowns/{report}/submit',       [Api\Skycable\TeardownController::class, 'submit']);
    Route::put('teardowns/{report}/review',        [Api\Skycable\TeardownController::class, 'review']);
    Route::put('teardowns/{report}/backend-approve',[Api\Skycable\TeardownController::class, 'backendApprove']);

    // Daily Reports
    Route::get('daily-reports',                             [Api\Skycable\DailyReportController::class, 'index']);
    Route::post('daily-reports',                            [Api\Skycable\DailyReportController::class, 'store']);
    Route::get('daily-reports/{dailyReport}',               [Api\Skycable\DailyReportController::class, 'show']);
    Route::get('daily-reports/{dailyReport}/missing-images',[Api\Skycable\DailyReportController::class, 'missingImages']);
    Route::put('daily-reports/{dailyReport}/subcon-review', [Api\Skycable\DailyReportController::class, 'subconReview']);
    Route::put('daily-reports/{dailyReport}/backend-approve',[Api\Skycable\DailyReportController::class, 'backendApprove']);

    // Warehouse & Stock
    Route::get('warehouses',                        [Api\Skycable\WarehouseController::class, 'index']);
    Route::post('warehouses',                       [Api\Skycable\WarehouseController::class, 'store']);
    Route::get('warehouses/{warehouse}',            [Api\Skycable\WarehouseController::class, 'show']);
    Route::put('warehouses/{warehouse}',            [Api\Skycable\WarehouseController::class, 'update']);
    Route::get('warehouses/{warehouse}/stocks',     [Api\Skycable\WarehouseController::class, 'stocks']);
    Route::get('warehouses/{warehouse}/receipts',   [Api\Skycable\WarehouseController::class, 'receipts']);
    Route::post('warehouse-receipts',                     [Api\Skycable\WarehouseController::class, 'receiveStock']);
    Route::get('warehouse-receipts/{receipt}',            [Api\Skycable\WarehouseController::class, 'showReceipt']);
    Route::put('warehouse-receipts/{receipt}/approve',    [Api\Skycable\WarehouseController::class, 'approveReceipt']);
    Route::put('warehouse-receipts/{receipt}/arrive',     [Api\Skycable\WarehouseController::class, 'arrive']);
    Route::put('warehouse-receipts/{receipt}/start-unload',[Api\Skycable\WarehouseController::class, 'startUnload']);
    Route::post('warehouse-receipts/{receipt}/verify',    [Api\Skycable\WarehouseController::class, 'verifyAndApprove']);

    // Pickup Requests & Deliveries
    Route::get('pickup-requests',                        [Api\Skycable\DeliveryController::class, 'pickupRequests']);
    Route::post('pickup-requests',                       [Api\Skycable\DeliveryController::class, 'createPickupRequest']);
    Route::put('pickup-requests/{pickupRequest}/approve',[Api\Skycable\DeliveryController::class, 'approvePickupRequest']);
    Route::get('deliveries',                             [Api\Skycable\DeliveryController::class, 'deliveries']);
    Route::post('deliveries/{pickupRequest}/dispatch',   [Api\Skycable\DeliveryController::class, 'dispatch']);
    Route::put('deliveries/{delivery}/accept',           [Api\Skycable\DeliveryController::class, 'accept']);
    Route::get('pull-out-requests',                      [Api\Skycable\DeliveryController::class, 'pullOutRequests']);
    Route::post('pull-out-requests',                     [Api\Skycable\DeliveryController::class, 'createPullOut']);
    Route::put('pull-out-requests/{pullOutRequest}/approve',  [Api\Skycable\DeliveryController::class, 'approvePullOut']);
    Route::get('pull-out-requests/{pullOutRequest}/delivery', [Api\Skycable\DeliveryController::class, 'pullOutDelivery']);
    Route::put('deliveries/{delivery}/assign-driver',         [Api\Skycable\DeliveryController::class, 'assignDriver']);
    Route::post('deliveries/{delivery}/start',                [Api\Skycable\DeliveryController::class, 'startDelivery']);
    Route::post('deliveries/{delivery}/arrive',               [Api\Skycable\DeliveryController::class, 'arrive']);
    Route::get('deliveries/incoming/{warehouseId}',           [Api\Skycable\DeliveryController::class, 'incomingDeliveries']);
    Route::get('deliveries/{delivery}/tracking',              [Api\Skycable\DeliveryController::class, 'tracking']);
    Route::get('driver/deliveries',                           [Api\Skycable\DeliveryController::class, 'driverDeliveries']);
    Route::get('drivers',                                     [Api\Skycable\DeliveryController::class, 'drivers']);
    Route::put('users/{user}/toggle-driver',                  [Api\Skycable\DeliveryController::class, 'toggleDriverRole']);

    // Notifications
    Route::get('notifications',              [Api\Skycable\NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [Api\Skycable\NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all',    [Api\Skycable\NotificationController::class, 'markAllRead']);
    Route::post('notifications/{notification}/read', [Api\Skycable\NotificationController::class, 'markRead']);

    // Lineman live location (mobile → backend → web dashboard)
    Route::post('lineman/location',  [Api\Skycable\LinemanLocationController::class, 'store']);
    Route::get('lineman/locations',  [Api\Skycable\LinemanLocationController::class, 'index']);

    // Active users, audit, support
    Route::get('active-users',                  [Api\ActiveUserController::class, 'index']);
    Route::get('audit-logs',                    [Api\AuditLogController::class, 'index']);
    Route::post('support/tickets',              [Api\SupportTicketController::class, 'store']);
    Route::get('support/tickets',               [Api\SupportTicketController::class, 'index']);
    Route::get('support/tickets/{supportTicket}',[Api\SupportTicketController::class, 'show']);
    Route::post('support/tickets/{supportTicket}/reply',[Api\SupportTicketController::class, 'reply']);
    Route::get('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'show']);
    Route::post('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'startOrJoin']);
    Route::put('support/ticket-sessions/{supportTicketSession}/end', [Api\SupportTicketSessionController::class, 'end']);
});

// ── Globe ─────────────────────────────────────────────────────────────────────

Route::prefix('globe/auth')->group(function () {
    Route::post('login',           [Api\Globe\AuthController::class, 'login']);
    Route::post('forgot-password', [Api\Globe\AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [Api\Globe\AuthController::class, 'resetPassword']);
});

Route::prefix('globe')->middleware(['auth:sanctum', 'company:globe'])->group(function () {
    Route::post('auth/logout',          [Api\Globe\AuthController::class, 'logout']);
    Route::get('auth/me',               [Api\Globe\AuthController::class, 'me']);
    Route::put('auth/profile',          [Api\Globe\AuthController::class, 'updateProfile']);
    Route::post('auth/change-password', [Api\Globe\AuthController::class, 'changePassword']);

    // PSGC
    Route::get('locations/regions',   [Api\PsgcController::class, 'regions']);
    Route::get('locations/provinces', [Api\PsgcController::class, 'provinces']);
    Route::get('locations/cities',    [Api\PsgcController::class, 'cities']);
    Route::get('locations/barangays', [Api\PsgcController::class, 'barangays']);

    // Poles
    Route::get('poles',        [Api\Globe\PoleController::class, 'index']);
    Route::post('poles',       [Api\Globe\PoleController::class, 'store']);
    Route::get('poles/{pole}', [Api\Globe\PoleController::class, 'show']);

    // NAP Boxes & Ports
    Route::get('nap-boxes',                           [Api\Globe\NapBoxController::class, 'index']); // all nap boxes (no pole filter)
    Route::get('poles/{pole}/nap-boxes',              [Api\Globe\NapBoxController::class, 'index']);
    Route::post('nap-boxes',                          [Api\Globe\NapBoxController::class, 'store']);
    Route::get('nap-boxes/{napBox}',                  [Api\Globe\NapBoxController::class, 'show']);
    Route::put('nap-boxes/{napBox}',                  [Api\Globe\NapBoxController::class, 'update']);
    Route::get('nap-boxes/{napBox}/ports',            [Api\Globe\NapBoxController::class, 'ports']);
    Route::put('nap-boxes/{napBox}/ports/{portNumber}',[Api\Globe\NapBoxController::class, 'updatePort']);

    // Surveys
    Route::get('nap-boxes/{napBox}/surveys',  [Api\Globe\SurveyController::class, 'index']);
    Route::post('nap-boxes/{napBox}/surveys', [Api\Globe\SurveyController::class, 'store']);
    Route::get('surveys/{survey}',            [Api\Globe\SurveyController::class, 'show']);
    Route::put('surveys/{survey}/submit',     [Api\Globe\SurveyController::class, 'submit']);

    // Tickets
    Route::get('tickets',                    [Api\Globe\TicketController::class, 'index']);
    Route::post('tickets',                   [Api\Globe\TicketController::class, 'store']);
    Route::get('tickets/{ticket}',           [Api\Globe\TicketController::class, 'show']);
    Route::put('tickets/{ticket}',           [Api\Globe\TicketController::class, 'update']);
    Route::post('tickets/{ticket}/claim',    [Api\Globe\TicketController::class, 'claim']);
    Route::put('tickets/{ticket}/cancel',    [Api\Globe\TicketController::class, 'cancel']);

    // Teardown Reports
    Route::post('tickets/{ticket}/teardown',        [Api\Globe\TeardownController::class, 'store']);
    Route::get('teardowns/{teardownReport}',        [Api\Globe\TeardownController::class, 'show']);
    Route::put('teardowns/{teardownReport}/approve',[Api\Globe\TeardownController::class, 'approve']);

    // Daily Reports
    Route::get('daily-reports',                    [Api\Globe\DailyReportController::class, 'index']);
    Route::post('daily-reports',                   [Api\Globe\DailyReportController::class, 'store']);
    Route::get('daily-reports/{dailyReport}',      [Api\Globe\DailyReportController::class, 'show']);
    Route::put('daily-reports/{dailyReport}/approve',[Api\Globe\DailyReportController::class, 'approve']);

    // Active users, audit, support
    Route::get('active-users',                   [Api\ActiveUserController::class, 'index']);
    Route::get('audit-logs',                     [Api\AuditLogController::class, 'index']);
    Route::post('support/tickets',               [Api\SupportTicketController::class, 'store']);
    Route::get('support/tickets',                [Api\SupportTicketController::class, 'index']);
    Route::get('support/tickets/{supportTicket}',[Api\SupportTicketController::class, 'show']);
    Route::post('support/tickets/{supportTicket}/reply',[Api\SupportTicketController::class, 'reply']);
    Route::get('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'show']);
    Route::post('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'startOrJoin']);
    Route::put('support/ticket-sessions/{supportTicketSession}/end', [Api\SupportTicketSessionController::class, 'end']);
});

// ── Meralco ───────────────────────────────────────────────────────────────────

Route::prefix('meralco/auth')->group(function () {
    Route::post('login',           [Api\Meralco\AuthController::class, 'login']);
    Route::post('forgot-password', [Api\Meralco\AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [Api\Meralco\AuthController::class, 'resetPassword']);
});

Route::prefix('meralco')->middleware(['auth:sanctum', 'company:meralco'])->group(function () {
    Route::post('auth/logout',          [Api\Meralco\AuthController::class, 'logout']);
    Route::get('auth/me',               [Api\Meralco\AuthController::class, 'me']);
    Route::put('auth/profile',          [Api\Meralco\AuthController::class, 'updateProfile']);
    Route::post('auth/change-password', [Api\Meralco\AuthController::class, 'changePassword']);

    // PSGC
    Route::get('locations/regions',   [Api\PsgcController::class, 'regions']);
    Route::get('locations/provinces', [Api\PsgcController::class, 'provinces']);
    Route::get('locations/cities',    [Api\PsgcController::class, 'cities']);
    Route::get('locations/barangays', [Api\PsgcController::class, 'barangays']);

    // Poles (read-only)
    Route::get('poles',                          [Api\Meralco\PoleController::class, 'index']);
    Route::get('poles/{pole}',                   [Api\Meralco\PoleController::class, 'show']);
    Route::get('poles/{pole}/teardown-proof',    [Api\Meralco\PoleController::class, 'teardownProof']);
    Route::get('summary',                        [Api\Meralco\SummaryController::class, 'index']);

    // Support tickets
    Route::post('support/tickets',               [Api\SupportTicketController::class, 'store']);
    Route::get('support/tickets',                [Api\SupportTicketController::class, 'index']);
    Route::get('support/tickets/{supportTicket}',[Api\SupportTicketController::class, 'show']);
    Route::post('support/tickets/{supportTicket}/reply',[Api\SupportTicketController::class, 'reply']);
    Route::get('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'show']);
    Route::post('support/tickets/{supportTicket}/session', [Api\SupportTicketSessionController::class, 'startOrJoin']);
    Route::put('support/ticket-sessions/{supportTicketSession}/end', [Api\SupportTicketSessionController::class, 'end']);
});
