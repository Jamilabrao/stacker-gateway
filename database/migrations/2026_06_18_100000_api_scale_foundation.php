<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_applications')) {
            Schema::table('api_applications', function (Blueprint $table) {
                if (! Schema::hasColumn('api_applications', 'is_legacy')) {
                    $table->boolean('is_legacy')->default(true)->after('is_active');
                }
                if (! Schema::hasColumn('api_applications', 'scopes')) {
                    $table->json('scopes')->nullable()->after('is_legacy');
                }
                if (! Schema::hasColumn('api_applications', 'strict_idempotency')) {
                    $table->boolean('strict_idempotency')->default(false)->after('scopes');
                }
                if (! Schema::hasColumn('api_applications', 'async_payments')) {
                    $table->boolean('async_payments')->default(false)->after('strict_idempotency');
                }
                if (! Schema::hasColumn('api_applications', 'rate_limit_tier')) {
                    $table->string('rate_limit_tier', 32)->default('legacy')->after('async_payments');
                }
                if (! Schema::hasColumn('api_applications', 'legacy_api_key_sha256')) {
                    $table->string('legacy_api_key_sha256', 64)->nullable()->index()->after('api_key_hash');
                }
            });

            DB::table('api_applications')->update([
                'is_legacy' => true,
                'scopes' => json_encode(['*']),
                'strict_idempotency' => false,
                'async_payments' => false,
                'rate_limit_tier' => 'legacy',
            ]);
        }

        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('api_application_id')->nullable()->index();
                $table->string('name');
                $table->string('public_key', 64)->unique();
                $table->string('secret_key_hash');
                $table->json('scopes');
                $table->json('allowed_ips')->nullable();
                $table->boolean('strict_idempotency')->default(true);
                $table->boolean('async_payments')->default(false);
                $table->string('rate_limit_tier', 32)->default('standard');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_idempotency_keys')) {
            Schema::create('api_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('api_application_id')->nullable();
                $table->unsignedBigInteger('api_key_id')->nullable();
                $table->string('idempotency_key', 128);
                $table->string('request_hash', 64);
                $table->string('status', 32)->default('completed');
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->json('response_body')->nullable();
                $table->string('resource_type', 64)->nullable();
                $table->string('resource_id', 64)->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->unique(['tenant_id', 'idempotency_key'], 'api_idempotency_tenant_key_unique');
            });
        }

        if (! Schema::hasTable('api_webhook_deliveries')) {
            Schema::create('api_webhook_deliveries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('api_application_id')->nullable()->index();
                $table->unsignedBigInteger('api_key_id')->nullable();
                $table->string('event', 64)->index();
                $table->string('event_id', 36)->unique();
                $table->json('payload');
                $table->string('url', 2048);
                $table->unsignedSmallInteger('attempt')->default(0);
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedSmallInteger('last_status_code')->nullable();
                $table->text('last_response_body')->nullable();
                $table->timestamp('next_retry_at')->nullable()->index();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('withdrawals')) {
            Schema::table('withdrawals', function (Blueprint $table) {
                if (! Schema::hasColumn('withdrawals', 'api_application_id')) {
                    $table->unsignedBigInteger('api_application_id')->nullable()->index()->after('user_id');
                }
                if (! Schema::hasColumn('withdrawals', 'api_key_id')) {
                    $table->unsignedBigInteger('api_key_id')->nullable()->index()->after('api_application_id');
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! $this->indexExists('orders', 'orders_gateway_gateway_id_index')) {
                    $table->index(['gateway', 'gateway_id'], 'orders_gateway_gateway_id_index');
                }
                if (! $this->indexExists('orders', 'orders_tenant_status_created_index')) {
                    $table->index(['tenant_id', 'status', 'created_at'], 'orders_tenant_status_created_index');
                }
                if (Schema::hasColumn('orders', 'api_application_id')
                    && ! $this->indexExists('orders', 'orders_api_app_status_index')) {
                    $table->index(['api_application_id', 'status'], 'orders_api_app_status_index');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if ($this->indexExists('orders', 'orders_gateway_gateway_id_index')) {
                    $table->dropIndex('orders_gateway_gateway_id_index');
                }
                if ($this->indexExists('orders', 'orders_tenant_status_created_index')) {
                    $table->dropIndex('orders_tenant_status_created_index');
                }
                if ($this->indexExists('orders', 'orders_api_app_status_index')) {
                    $table->dropIndex('orders_api_app_status_index');
                }
            });
        }

        if (Schema::hasTable('withdrawals')) {
            Schema::table('withdrawals', function (Blueprint $table) {
                if (Schema::hasColumn('withdrawals', 'api_key_id')) {
                    $table->dropColumn('api_key_id');
                }
                if (Schema::hasColumn('withdrawals', 'api_application_id')) {
                    $table->dropColumn('api_application_id');
                }
            });
        }

        Schema::dropIfExists('api_webhook_deliveries');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_keys');

        if (Schema::hasTable('api_applications')) {
            Schema::table('api_applications', function (Blueprint $table) {
                foreach (['rate_limit_tier', 'async_payments', 'strict_idempotency', 'scopes', 'is_legacy', 'legacy_api_key_sha256'] as $col) {
                    if (Schema::hasColumn('api_applications', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list({$table})");

            return collect($indexes)->contains(fn ($idx) => ($idx->name ?? '') === $indexName);
        }

        if ($driver === 'pgsql') {
            $result = $connection->select(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );

            return count($result) > 0;
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return count($result) > 0;
    }
};
