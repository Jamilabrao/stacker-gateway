<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metrics_ip_geo_cache')) {
            Schema::create('metrics_ip_geo_cache', function (Blueprint $table) {
                $table->id();
                $table->string('ip_hash', 64)->unique();
                $table->string('country', 120)->nullable();
                $table->string('region', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('isp', 255)->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('metrics_sessions')) {
            Schema::create('metrics_sessions', function (Blueprint $table) {
                $table->id();
                $table->uuid('session_key')->unique();
                $table->uuid('visitor_key')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->uuid('product_id')->nullable()->index();
                $table->unsignedBigInteger('offer_id')->nullable()->index();
                $table->unsignedBigInteger('plan_id')->nullable()->index();
                $table->unsignedBigInteger('affiliate_user_id')->nullable()->index();
                $table->string('affiliate_ref', 64)->nullable()->index();
                $table->unsignedBigInteger('coproducer_user_id')->nullable()->index();
                $table->string('campaign_code', 120)->nullable()->index();
                $table->string('landing_url', 2048)->nullable();
                $table->string('referrer', 2048)->nullable();
                $table->string('utm_source', 255)->nullable()->index();
                $table->string('utm_medium', 255)->nullable()->index();
                $table->string('utm_campaign', 255)->nullable()->index();
                $table->string('utm_content', 255)->nullable();
                $table->string('utm_term', 255)->nullable();
                $table->string('fbclid', 512)->nullable();
                $table->string('gclid', 512)->nullable();
                $table->string('ttclid', 512)->nullable();
                $table->string('src', 255)->nullable();
                $table->string('sck', 255)->nullable();
                $table->string('subid', 255)->nullable();
                $table->string('subid2', 255)->nullable();
                $table->string('subid3', 255)->nullable();
                $table->json('tracking_params')->nullable();
                $table->string('device_type', 32)->nullable()->index();
                $table->string('os_name', 64)->nullable();
                $table->string('browser_name', 64)->nullable();
                $table->string('user_agent', 1024)->nullable();
                $table->string('ip_hash', 64)->nullable()->index();
                $table->string('ip_masked', 64)->nullable();
                $table->string('country', 120)->nullable()->index();
                $table->string('region', 120)->nullable()->index();
                $table->string('city', 120)->nullable()->index();
                $table->timestamp('first_touch_at')->nullable()->index();
                $table->timestamp('last_touch_at')->nullable()->index();
                $table->timestamp('converted_at')->nullable()->index();
                $table->unsignedInteger('events_count')->default(0);
                $table->unsignedInteger('clicks_count')->default(0);
                $table->timestamps();

                $table->index(['tenant_id', 'first_touch_at']);
                $table->index(['tenant_id', 'converted_at']);
            });
        }

        if (! Schema::hasTable('metrics_events')) {
            Schema::create('metrics_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 128)->unique();
                $table->string('event_name', 64)->index();
                $table->unsignedBigInteger('metrics_session_id')->nullable()->index();
                $table->uuid('session_key')->nullable()->index();
                $table->uuid('visitor_key')->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->uuid('product_id')->nullable()->index();
                $table->unsignedBigInteger('offer_id')->nullable()->index();
                $table->unsignedBigInteger('plan_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('checkout_session_id')->nullable()->index();
                $table->unsignedBigInteger('affiliate_user_id')->nullable()->index();
                $table->string('affiliate_ref', 64)->nullable()->index();
                $table->unsignedBigInteger('coproducer_user_id')->nullable()->index();
                $table->string('campaign_code', 120)->nullable()->index();
                $table->string('destination_url', 2048)->nullable();
                $table->string('referrer', 2048)->nullable();
                $table->string('utm_source', 255)->nullable()->index();
                $table->string('utm_medium', 255)->nullable()->index();
                $table->string('utm_campaign', 255)->nullable()->index();
                $table->string('utm_content', 255)->nullable();
                $table->string('utm_term', 255)->nullable();
                $table->string('fbclid', 512)->nullable();
                $table->string('gclid', 512)->nullable();
                $table->string('ttclid', 512)->nullable();
                $table->string('src', 255)->nullable();
                $table->string('sck', 255)->nullable();
                $table->string('subid', 255)->nullable();
                $table->string('subid2', 255)->nullable();
                $table->string('subid3', 255)->nullable();
                $table->json('tracking_params')->nullable();
                $table->string('device_type', 32)->nullable()->index();
                $table->string('os_name', 64)->nullable()->index();
                $table->string('browser_name', 64)->nullable()->index();
                $table->string('user_agent', 1024)->nullable();
                $table->string('ip_hash', 64)->nullable()->index();
                $table->string('ip_masked', 64)->nullable();
                $table->string('country', 120)->nullable()->index();
                $table->string('region', 120)->nullable()->index();
                $table->string('city', 120)->nullable()->index();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('isp', 255)->nullable();
                $table->string('timezone', 64)->nullable();
                $table->string('conversion_status', 64)->nullable()->index();
                $table->decimal('amount', 12, 2)->nullable();
                $table->string('currency', 8)->nullable();
                $table->unsignedInteger('seconds_to_convert')->nullable();
                $table->boolean('geo_enriched')->default(false)->index();
                $table->json('properties')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['tenant_id', 'occurred_at']);
                $table->index(['tenant_id', 'event_name', 'occurred_at']);
                $table->index(['tenant_id', 'product_id', 'occurred_at']);
                $table->index(['order_id', 'event_name']);
            });
        }

        if (! Schema::hasTable('metrics_daily_stats')) {
            Schema::create('metrics_daily_stats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->date('stat_date')->index();
                $table->uuid('product_id')->nullable()->index();
                $table->string('dimension', 64)->default('total')->index();
                $table->string('dimension_value', 255)->nullable()->index();
                $table->unsignedInteger('unique_visitors')->default(0);
                $table->unsignedInteger('sessions')->default(0);
                $table->unsignedInteger('clicks')->default(0);
                $table->unsignedInteger('checkout_views')->default(0);
                $table->unsignedInteger('checkouts_started')->default(0);
                $table->unsignedInteger('pix_created')->default(0);
                $table->unsignedInteger('payments_approved')->default(0);
                $table->unsignedInteger('payments_refused')->default(0);
                $table->unsignedInteger('refunds')->default(0);
                $table->decimal('gross_revenue', 14, 2)->default(0);
                $table->decimal('net_revenue', 14, 2)->default(0);
                $table->unsignedBigInteger('seconds_to_convert_sum')->default(0);
                $table->unsignedInteger('seconds_to_convert_count')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'stat_date', 'product_id', 'dimension', 'dimension_value'], 'metrics_daily_stats_unique');
            });
        }

        if (! Schema::hasTable('metrics_report_access_logs')) {
            Schema::create('metrics_report_access_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('report', 64);
                $table->json('filters')->nullable();
                $table->string('ip_masked', 64)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('checkout_sessions') && ! Schema::hasColumn('checkout_sessions', 'metrics_session_key')) {
            Schema::table('checkout_sessions', function (Blueprint $table) {
                $table->uuid('metrics_session_key')->nullable()->after('session_token')->index();
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'metrics_session_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->uuid('metrics_session_key')->nullable()->after('checkout_session_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'metrics_session_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('metrics_session_key');
            });
        }
        if (Schema::hasTable('checkout_sessions') && Schema::hasColumn('checkout_sessions', 'metrics_session_key')) {
            Schema::table('checkout_sessions', function (Blueprint $table) {
                $table->dropColumn('metrics_session_key');
            });
        }

        Schema::dropIfExists('metrics_report_access_logs');
        Schema::dropIfExists('metrics_daily_stats');
        Schema::dropIfExists('metrics_events');
        Schema::dropIfExists('metrics_sessions');
        Schema::dropIfExists('metrics_ip_geo_cache');
    }
};
