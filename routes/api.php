<?php
use App\Http\Controllers\Api\Admin\CalendarController as AdminCalendarController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\UploadController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AdminAuthController;
use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Storefront\CategoryController;
use App\Http\Controllers\Api\Storefront\OrderController;
use App\Http\Controllers\Api\Storefront\ProductController;
use App\Http\Controllers\Api\Storefront\ReviewController;
use App\Http\Controllers\Api\Storefront\SettingsController;
use Illuminate\Support\Facades\Route;
Route::prefix('auth')->group(function () {
    Route::prefix('admin')->group(function () {
        Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');
        Route::post('2fa/challenge', [AdminAuthController::class, 'challenge'])->middleware('throttle:2fa-challenge');
        Route::post('forgot-password', [AdminAuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
        Route::post('reset-password', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:forgot-password');
        Route::middleware(['auth:admin', 'admin.active'])->group(function () {
            Route::get('me', [AdminAuthController::class, 'me']);
            Route::post('logout', [AdminAuthController::class, 'logout']);
            Route::put('password', [AdminAuthController::class, 'updatePassword']);
            Route::post('2fa/enable', [AdminAuthController::class, 'enableTwoFactor']);
            Route::post('2fa/confirm', [AdminAuthController::class, 'confirmTwoFactor']);
            Route::post('2fa/disable', [AdminAuthController::class, 'disableTwoFactor']);
            Route::post('2fa/recovery-codes', [AdminAuthController::class, 'regenerateRecoveryCodes']);
        });
    });
    Route::prefix('customer')->group(function () {
        Route::post('register', [CustomerAuthController::class, 'register'])->middleware('throttle:customer-login');
        Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:customer-login');
        Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
        Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:forgot-password');
        Route::middleware('auth:customer')->group(function () {
            Route::get('me', [CustomerAuthController::class, 'me']);
            Route::post('logout', [CustomerAuthController::class, 'logout']);
            Route::put('password', [CustomerAuthController::class, 'updatePassword']);
        });
    });
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::middleware(['auth:admin', 'admin.active'])->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
    });
});
Route::prefix('admin')->middleware(['auth:admin', 'admin.active', 'throttle:admin-api'])->group(function () {
    Route::prefix('dashboard')->group(function () {
        Route::get('summary', [DashboardController::class, 'summary']);
        Route::get('sales', [DashboardController::class, 'sales']);
        Route::get('status-breakdown', [DashboardController::class, 'statusBreakdown']);
        Route::get('top-products', [DashboardController::class, 'topProducts']);
    });
    Route::get('products', [AdminProductController::class, 'index']);
    Route::patch('products/bulk', [AdminProductController::class, 'bulk']);
    Route::get('products/{id}', [AdminProductController::class, 'show']);
    Route::post('products', [AdminProductController::class, 'store']);
    Route::put('products/{id}', [AdminProductController::class, 'update']);
    Route::delete('products/{id}', [AdminProductController::class, 'destroy']);
    Route::post('uploads', [UploadController::class, 'store'])->middleware('throttle:uploads');
    Route::get('categories', [AdminCategoryController::class, 'index']);
    Route::post('categories', [AdminCategoryController::class, 'store']);
    Route::put('categories/{id}', [AdminCategoryController::class, 'update']);
    Route::delete('categories/{id}', [AdminCategoryController::class, 'destroy']);
    Route::get('orders', [AdminOrderController::class, 'index']);
    Route::get('orders/{id}', [AdminOrderController::class, 'show']);
    Route::patch('orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::get('customers', [AdminCustomerController::class, 'index']);
    Route::get('customers/{id}', [AdminCustomerController::class, 'show']);
    Route::get('customers/{id}/orders', [AdminCustomerController::class, 'orders']);
    Route::patch('customers/{id}/flag', [AdminCustomerController::class, 'flag']);
    Route::get('reviews', [AdminReviewController::class, 'index']);
    Route::patch('reviews/{id}', [AdminReviewController::class, 'update']);
    Route::delete('reviews/{id}', [AdminReviewController::class, 'destroy']);
    Route::get('calendar/events', [AdminCalendarController::class, 'index']);
    Route::post('calendar/events', [AdminCalendarController::class, 'store']);
    Route::put('calendar/events/{id}', [AdminCalendarController::class, 'update']);
    Route::delete('calendar/events/{id}', [AdminCalendarController::class, 'destroy']);
    Route::get('settings', [AdminSettingsController::class, 'show']);
    Route::put('settings', [AdminSettingsController::class, 'update'])->middleware('role:superadmin');
    Route::middleware('role:superadmin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::patch('users/{id}', [UserController::class, 'update']);
        Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword']);
    });
});
$storefrontRoutes = function (string $prefix = '') {
    Route::get('settings', [SettingsController::class, 'show'])->middleware('throttle:public-read');
    Route::get('categories', [CategoryController::class, 'index'])->middleware('throttle:public-read');
    Route::get('products', [ProductController::class, 'index'])->middleware('throttle:public-read');
    Route::get('products/{slug}/related', [ProductController::class, 'related'])->middleware('throttle:public-read');
    Route::get('products/{slug}', [ProductController::class, 'show'])->middleware('throttle:public-read');
    Route::get('reviews', [ReviewController::class, 'index'])->middleware('throttle:public-read');
    Route::post('orders/lookup', [OrderController::class, 'lookup'])->middleware('throttle:order-lookup');
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:order-create');
};
Route::prefix('storefront')->group(function () use ($storefrontRoutes) {
    $storefrontRoutes();
    Route::middleware('auth:customer')->prefix('customer')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{orderNumber}', [OrderController::class, 'show']);
    });
});
$storefrontRoutes();
