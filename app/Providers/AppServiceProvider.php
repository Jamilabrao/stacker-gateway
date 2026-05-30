<?php

namespace App\Providers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Listeners\CreditTenantWalletOnOrderCompleted;
use App\Listeners\ForgetInertiaSharedCacheOnOrderCompleted;
use App\Listeners\IncrementCouponUsageOnOrderCompleted;
use App\Listeners\SendAccessEmailOnOrderCompleted;
use App\Listeners\SendPanelPushOnBoletoGenerated;
use App\Listeners\SendPanelPushOnOrderCompleted;
use App\Listeners\SendPanelPushOnPixGenerated;
use App\Listeners\CademiEventSubscriber;
use App\Listeners\MetaConversionsEventSubscriber;
use App\Listeners\SpedyEventSubscriber;
use App\Listeners\UtmifyEventSubscriber;
use App\Listeners\SendApiApplicationWebhookListener;
use App\Listeners\WebhookEventSubscriber;
use App\Support\DockerSetupState;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RefundRequestPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(RefundRequest::class, RefundRequestPolicy::class);

        $this->ensureRuntimeDirectories();
        $this->fallbackRedisToDatabase();
        $this->fallbackInvalidQueueConnectionToSync();
        $this->bootCloudFolder();
        if (DockerSetupState::isDocker() && class_exists(\Illuminate\Support\Facades\Vite::class)) {
            \Illuminate\Support\Facades\Vite::useHotFile(storage_path('framework/vite.hot'));
        }

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(10)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('api', function (Request $request) {
            $publicKey = trim((string) $request->header('X-Public-Key', ''));
            if ($publicKey !== '') {
                return Limit::perMinute(120)->by('pk:'.$publicKey);
            }

            $app = $request->attributes->get('api_application');
            if ($app && isset($app->id)) {
                return Limit::perMinute(120)->by('app:'.$app->id);
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $checkoutLimits = config('getfy.checkout_security.rate_limits', []);

        RateLimiter::for('checkout-pay', function (Request $request) use ($checkoutLimits) {
            $method = strtolower((string) $request->input('payment_method', ''));
            if (in_array($method, ['pix', 'card', 'apple_pay', 'google_pay'], true)) {
                return Limit::none();
            }

            return Limit::perMinute((int) ($checkoutLimits['pay_per_minute'] ?? 20))
                ->by($request->ip());
        });

        RateLimiter::for('checkout-pix', function (Request $request) use ($checkoutLimits) {
            $method = strtolower((string) $request->input('payment_method', ''));
            if ($method !== 'pix') {
                return Limit::none();
            }

            return Limit::perMinute((int) ($checkoutLimits['pix_per_minute'] ?? 5))
                ->by($request->ip());
        });

        RateLimiter::for('checkout-card', function (Request $request) use ($checkoutLimits) {
            $method = strtolower((string) $request->input('payment_method', ''));
            if (! in_array($method, ['card', 'apple_pay', 'google_pay'], true)) {
                return Limit::none();
            }

            return Limit::perMinute((int) ($checkoutLimits['card_per_minute'] ?? 15))
                ->by($request->ip());
        });

        RateLimiter::for('checkout-pix-email', function (Request $request) use ($checkoutLimits) {
            $method = strtolower((string) $request->input('payment_method', ''));
            if ($method !== 'pix') {
                return Limit::none();
            }
            $email = strtolower(trim((string) $request->input('email', '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Limit::none();
            }

            return Limit::perMinutes(10, (int) ($checkoutLimits['pix_email_per_ten_minutes'] ?? 5))
                ->by('pix-email:'.sha1($email));
        });

        RateLimiter::for('checkout-cajupay-session', function (Request $request) use ($checkoutLimits) {
            return Limit::perMinute((int) ($checkoutLimits['cajupay_session_per_minute'] ?? 30))
                ->by($request->ip());
        });

        RateLimiter::for('checkout-cajupay-confirm', function (Request $request) use ($checkoutLimits) {
            return Limit::perMinute((int) ($checkoutLimits['cajupay_confirm_per_minute'] ?? 15))
                ->by($request->ip());
        });

        RateLimiter::for('checkout-track', function (Request $request) use ($checkoutLimits) {
            $token = trim((string) $request->input('session_token', ''));
            $key = $token !== '' ? 'track:'.$token : 'track-ip:'.$request->ip();

            return Limit::perMinute((int) ($checkoutLimits['track_per_minute'] ?? 30))
                ->by($key);
        });

        RateLimiter::for('checkout-coupon', function (Request $request) use ($checkoutLimits) {
            return Limit::perMinute((int) ($checkoutLimits['coupon_per_minute'] ?? 20))
                ->by($request->ip());
        });

        RateLimiter::for('checkout-shipping-quote', function (Request $request) use ($checkoutLimits) {
            return Limit::perMinute((int) ($checkoutLimits['shipping_quote_per_minute'] ?? 30))
                ->by($request->ip());
        });

        Queue::after(function (): void {
            Cache::put('queue_heartbeat', now()->toIso8601String(), now()->addMinutes(5));
        });

        Event::listen(OrderCompleted::class, CreditTenantWalletOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, ForgetInertiaSharedCacheOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, IncrementCouponUsageOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, SendAccessEmailOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, SendPanelPushOnOrderCompleted::class);
        Event::listen(PixGenerated::class, SendPanelPushOnPixGenerated::class);
        Event::listen(BoletoGenerated::class, SendPanelPushOnBoletoGenerated::class);
        Event::subscribe(WebhookEventSubscriber::class);
        Event::subscribe(SendApiApplicationWebhookListener::class);
        Event::subscribe(UtmifyEventSubscriber::class);
        Event::subscribe(MetaConversionsEventSubscriber::class);
        Event::subscribe(SpedyEventSubscriber::class);
        Event::subscribe(CademiEventSubscriber::class);

    }

    private function bootCloudFolder(): void
    {
        if (! is_dir(base_path('cloud'))) {
            return;
        }

        $bootstrap = base_path('cloud/bootstrap.php');
        if (! is_file($bootstrap)) {
            return;
        }

        try {
            $register = require $bootstrap;
            if (is_callable($register)) {
                $register($this->app);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function ensureRuntimeDirectories(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $paths = [
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Se Redis estiver configurado mas indisponível, usa database para cache, sessão e fila.
     */
    private function fallbackRedisToDatabase(): void
    {
        $usesRedis = config('cache.default') === 'redis'
            || config('session.driver') === 'redis'
            || config('queue.default') === 'redis';

        if (! $usesRedis) {
            return;
        }

        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            if (config('cache.default') === 'redis') {
                config(['cache.default' => 'database']);
            }
            if (config('session.driver') === 'redis') {
                config(['session.driver' => 'database']);
            }
            if (config('queue.default') === 'redis') {
                config(['queue.default' => 'database']);
            }
        }
    }

    private function fallbackInvalidQueueConnectionToSync(): void
    {
        $default = (string) config('queue.default', 'sync');
        $connections = config('queue.connections', []);
        if (! is_array($connections) || $connections === []) {
            config(['queue.default' => 'sync']);
            return;
        }
        if (! array_key_exists($default, $connections)) {
            config(['queue.default' => 'sync']);
        }
    }
}
