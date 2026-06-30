<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CloudSyncController;
use App\Http\Controllers\LocalSyncController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/storefront/products', [ProductController::class, 'index']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/device/attendance', [OperationsController::class, 'deviceAttendance'])->middleware(['device', 'throttle:120,1']);
    Route::middleware(['sync', 'throttle:240,1'])->group(function () {
        Route::get('/sync/products', [CloudSyncController::class, 'products']);
        Route::post('/sync/sales', [CloudSyncController::class, 'sale']);
        Route::post('/sync/attendance', [CloudSyncController::class, 'attendance']);
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
            Route::get('/devices', [OperationsController::class, 'devices']);
            Route::post('/devices', [OperationsController::class, 'deviceStore']);
        });
        Route::post('/pos/checkout', [OperationsController::class, 'pos']);
        Route::get('/sales', [OperationsController::class, 'sales'])->middleware('role:admin,assistant,cashier');
        Route::get('/orders', [OperationsController::class, 'orders']);
        Route::post('/orders', [OperationsController::class, 'placeOrder']);
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
        Route::get('/reports', [OperationsController::class, 'report'])->middleware('role:admin,assistant');
        Route::get('/local-sync/status', [LocalSyncController::class, 'status'])->middleware('role:admin,assistant');
        Route::post('/local-sync/run', [LocalSyncController::class, 'run'])->middleware('role:admin,assistant');
    });
});

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
