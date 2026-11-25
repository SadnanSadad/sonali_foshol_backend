<?php

namespace App\Providers;

use App\Models\Customer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePasswordResetUrls();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)
            ->by(md5((string) ($request->input('login') ?? $request->input('email')).'|'.$request->ip()))
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429)));

        RateLimiter::for('customer-login', fn (Request $request) => Limit::perMinute(5)
            ->by(md5((string) $request->input('email').'|'.$request->ip()))
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429)));

        RateLimiter::for('2fa-challenge', fn (Request $request) => Limit::perMinute(5)
            ->by(md5((string) $request->input('challenge_token').'|'.$request->ip()))
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429)));

        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perMinute(3)
            ->by(md5((string) $request->input('email').'|'.$request->ip()))
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429)));

        RateLimiter::for('order-lookup', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip())
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429)));

        RateLimiter::for('order-create', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip())
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429)));

        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(120)
            ->by($request->ip())
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many requests.'], 429)));

        RateLimiter::for('admin-api', fn (Request $request) => Limit::perMinute(300)
            ->by(optional($request->user('admin'))->id ?: $request->ip())
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many requests.'], 429)));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)
            ->by(optional($request->user('admin'))->id ?: $request->ip())
            ->response(fn () => response()->json(['success' => false, 'message' => 'Too many uploads.'], 429)));
    }

    private function configurePasswordResetUrls(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $base = $notifiable instanceof Customer
                ? env('FRONTEND_STOREFRONT_URL', 'http://localhost:5174')
                : env('FRONTEND_ADMIN_URL', 'http://localhost:5173');

            return $base.'/reset-password?token='.$token.'&email='.urlencode($notifiable->email);
        });
    }
}
