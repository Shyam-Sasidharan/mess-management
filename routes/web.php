<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'permission'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/search', SearchController::class)->name('search');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}', [NotificationController::class, 'read'])->name('notifications.read');

    Route::middleware('permission:manage-business')->group(function () {
        Route::resource('customers', CustomerController::class);
        Route::get('/customers/{customer}/renew', [CustomerController::class, 'renew'])->name('customers.renew');
        Route::post('/customers/{customer}/renew', [CustomerController::class, 'storeRenewal'])->name('customers.renew.store');
        Route::patch('/customers/{customer}/pause', [CustomerController::class, 'pause'])->name('customers.pause');
        Route::patch('/customers/{customer}/resume', [CustomerController::class, 'resume'])->name('customers.resume');
        Route::resource('payments', PaymentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('expenses', ExpenseController::class)->except(['show']);
        Route::get('/expenses/{expense}/bill', [ExpenseController::class, 'bill'])->name('expenses.bill');
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('/expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        Route::get('/reports/{type?}', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
    });

    Route::middleware('permission:manage-deliveries')->group(function () {
        Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::patch('/deliveries/{delivery}', [DeliveryController::class, 'update'])->name('deliveries.update');
    });
});
