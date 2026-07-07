<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CloudSyncController;
use App\Http\Controllers\LocalSyncController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/storefront/products', [ProductController::class, 'index']);
    Route::get('/auth/capabilities', [AuthController::class, 'capabilities']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/auth/verify-email', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth-otp-verify');
    Route::post('/auth/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:auth-otp-resend');
    Route::post('/auth/password-tickets', [AuthController::class, 'passwordTicket'])->middleware('throttle:3,10');
    Route::post('/auth/password-ticket-status', [AuthController::class, 'passwordTicketStatus'])->middleware('throttle:12,1');
    Route::post('/device/attendance', [OperationsController::class, 'deviceAttendance'])->middleware(['device', 'throttle:120,1']);
    Route::get('/device/employees', [OperationsController::class, 'deviceEmployees'])->middleware(['device', 'throttle:60,1']);
    Route::get('/device/face-enrollments', [OperationsController::class, 'deviceFaceEnrollments'])->middleware(['device', 'throttle:60,1']);
    Route::post('/device/face-enrollments', [OperationsController::class, 'deviceFaceEnrollmentStore'])->middleware(['device', 'throttle:30,1']);
    Route::delete('/device/face-enrollments/{subjectId}', [OperationsController::class, 'deviceFaceEnrollmentDestroy'])->middleware(['device', 'throttle:30,1']);
    Route::post('/payments/webhooks/stripe', [PaymentController::class, 'stripe'])->middleware('throttle:240,1');
    Route::post('/payments/webhooks/paymongo', [PaymentController::class, 'payMongo'])->middleware('throttle:240,1');
    Route::post('/payments/webhooks/maya', [PaymentController::class, 'maya'])->middleware('throttle:240,1');
    Route::middleware(['sync', 'throttle:240,1'])->group(function () {
        Route::get('/sync/products', [CloudSyncController::class, 'products']);
        Route::get('/sync/inventory-activity', [CloudSyncController::class, 'inventoryActivity']);
        Route::get('/sync/orders', [CloudSyncController::class, 'orders']);
        Route::get('/sync/attendance', [CloudSyncController::class, 'attendances']);
        Route::get('/sync/payroll-runs', [CloudSyncController::class, 'payrollRuns']);
        Route::get('/sync/configuration', [CloudSyncController::class, 'configuration']);
        Route::post('/sync/sales', [CloudSyncController::class, 'sale']);
        Route::post('/sync/attendance', [CloudSyncController::class, 'attendance']);
        Route::post('/sync/payroll-runs', [CloudSyncController::class, 'payrollRun']);
        Route::post('/sync/users', [CloudSyncController::class, 'user']);
        Route::post('/sync/employees', [CloudSyncController::class, 'employee']);
        Route::post('/sync/orders', [CloudSyncController::class, 'order']);
        Route::post('/sync/order-status', [CloudSyncController::class, 'orderStatus']);
        Route::post('/sync/devices', [CloudSyncController::class, 'device']);
        Route::post('/sync/face-enrollments', [CloudSyncController::class, 'faceEnrollment']);
    });

    Route::middleware('auth')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::put('/auth/password', [AuthController::class, 'password']);
        Route::get('/dashboard', [OperationsController::class, 'dashboard']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/inventory/changes', [ProductController::class, 'changes']);
        Route::middleware('role:admin')->group(function () {
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);
            Route::post('/products/{product}/adjust', [ProductController::class, 'adjust']);
            Route::get('/users', [OperationsController::class, 'users']);
            Route::put('/users/{user}/role', [OperationsController::class, 'userRole']);
            Route::delete('/users/{user}', [OperationsController::class, 'userDestroy']);
            Route::put('/users/{user}/restore', [OperationsController::class, 'userRestore']);
            Route::post('/users/{user}/erase', [OperationsController::class, 'userErase']);
            Route::delete('/users/{user}/erase', [OperationsController::class, 'userErase']);
            Route::post('/admin/backup', [OperationsController::class, 'backup'])->middleware('throttle:3,10');
            Route::get('/password-tickets', [OperationsController::class, 'passwordTickets']);
            Route::post('/users/{user}/password-reset', [OperationsController::class, 'userPasswordReset']);
            Route::get('/devices', [OperationsController::class, 'devices']);
            Route::post('/devices', [OperationsController::class, 'deviceStore']);
            Route::delete('/devices/{device}', [OperationsController::class, 'deviceDestroy']);
        });
        Route::post('/pos/checkout', [OperationsController::class, 'pos']);
        Route::get('/sales', [OperationsController::class, 'sales'])->middleware('role:admin,assistant,cashier');
        Route::get('/orders', [OperationsController::class, 'orders']);
        Route::post('/orders', [OperationsController::class, 'placeOrder']);
        Route::post('/orders/{order}/payment-checkout', [PaymentController::class, 'checkout'])->middleware('throttle:20,1');
        Route::put('/orders/{order}/status', [OperationsController::class, 'orderStatus']);
        Route::post('/orders/{order}/receive', [OperationsController::class, 'receive']);
        Route::post('/orders/{order}/cancel', [OperationsController::class, 'cancel']);
        Route::get('/employees', [OperationsController::class, 'employees'])->middleware('role:admin,assistant');
        Route::post('/employees', [OperationsController::class, 'employeeStore'])->middleware('role:admin,assistant');
        Route::put('/employees/{employee}', [OperationsController::class, 'employeeUpdate'])->middleware('role:admin,assistant');
        Route::delete('/employees/{employee}', [OperationsController::class, 'employeeDestroy'])->middleware('role:admin');
        Route::get('/attendance', [OperationsController::class, 'attendance'])->middleware('role:admin,assistant');
        Route::post('/attendance', [OperationsController::class, 'attendanceStore'])->middleware('role:admin');
        Route::get('/payroll/preview', [OperationsController::class, 'payrollPreview'])->middleware('role:admin,assistant');
        Route::post('/payroll/runs', [OperationsController::class, 'payrollRun'])->middleware('role:admin,assistant');
        Route::get('/payroll/runs', [OperationsController::class, 'payrollRuns'])->middleware('role:admin,assistant');
        Route::get('/payroll/export', [OperationsController::class, 'payrollExport'])->middleware('role:admin,assistant');
        Route::get('/reports', [OperationsController::class, 'report'])->middleware('role:admin,assistant');
        Route::get('/local-sync/status', [LocalSyncController::class, 'status'])->middleware('role:admin,assistant');
        Route::post('/local-sync/run', [LocalSyncController::class, 'run'])->middleware('role:admin,assistant');
    });
});

Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:20,1');

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
