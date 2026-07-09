<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'affiliate_user_id')) {
                $table->unsignedBigInteger('affiliate_user_id')->nullable()->after('user_id');
                $table->index('affiliate_user_id');
            }
            if (! Schema::hasColumn('orders', 'affiliate_enrollment_id')) {
                $table->unsignedBigInteger('affiliate_enrollment_id')->nullable()->after('affiliate_user_id');
                $table->index('affiliate_enrollment_id');
            }
            if (! Schema::hasColumn('orders', 'sale_origin')) {
                $table->string('sale_origin', 32)->nullable()->after('affiliate_enrollment_id');
                $table->index('sale_origin');
            }
        });

        if (! Schema::hasTable('affiliate_commissions')) {
            Schema::create('affiliate_commissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('affiliate_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('affiliate_enrollment_id')->constrained('product_affiliate_enrollments')->cascadeOnDelete();
                $table->unsignedBigInteger('producer_tenant_id');
                $table->unsignedBigInteger('producer_user_id')->nullable();
                $table->string('product_id', 36);
                $table->string('sale_origin', 32)->nullable();
                $table->decimal('commission_percent', 8, 2)->default(0);
                $table->decimal('sale_gross', 12, 2)->default(0);
                $table->decimal('commission_gross', 12, 2)->default(0);
                $table->decimal('commission_fee', 12, 2)->default(0);
                $table->decimal('commission_net', 12, 2)->default(0);
                $table->string('status', 24)->default('pending');
                $table->unsignedBigInteger('wallet_transaction_id')->nullable();
                $table->string('affiliate_ref', 32)->nullable();
                $table->string('affiliate_link', 2048)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique('order_id');
                $table->index(['affiliate_user_id', 'status']);
                $table->index(['producer_tenant_id', 'created_at']);
                $table->index('product_id');
            });
        }

        $this->backfillOrderAffiliateColumns();
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');

        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            foreach (['affiliate_user_id', 'affiliate_enrollment_id', 'sale_origin'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function backfillOrderAffiliateColumns(): void
    {
        if (! Schema::hasColumn('orders', 'affiliate_user_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("
                UPDATE orders
                SET affiliate_user_id = NULLIF(metadata->>'affiliate_user_id', '')::bigint
                WHERE affiliate_user_id IS NULL
                  AND metadata->>'affiliate_user_id' IS NOT NULL
                  AND metadata->>'affiliate_user_id' ~ '^[0-9]+$'
            ");
            DB::statement("
                UPDATE orders
                SET affiliate_enrollment_id = NULLIF(metadata->>'affiliate_enrollment_id', '')::bigint
                WHERE affiliate_enrollment_id IS NULL
                  AND metadata->>'affiliate_enrollment_id' IS NOT NULL
                  AND metadata->>'affiliate_enrollment_id' ~ '^[0-9]+$'
            ");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("
                UPDATE orders
                SET affiliate_user_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.affiliate_user_id')) AS UNSIGNED)
                WHERE affiliate_user_id IS NULL
                  AND JSON_EXTRACT(metadata, '$.affiliate_user_id') IS NOT NULL
            ");
            DB::statement("
                UPDATE orders
                SET affiliate_enrollment_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.affiliate_enrollment_id')) AS UNSIGNED)
                WHERE affiliate_enrollment_id IS NULL
                  AND JSON_EXTRACT(metadata, '$.affiliate_enrollment_id') IS NOT NULL
            ");
        } elseif ($driver === 'sqlite') {
            $orders = DB::table('orders')->whereNotNull('metadata')->get(['id', 'metadata']);
            foreach ($orders as $row) {
                $meta = json_decode((string) $row->metadata, true);
                if (! is_array($meta)) {
                    continue;
                }
                $updates = [];
                if (! empty($meta['affiliate_user_id'])) {
                    $updates['affiliate_user_id'] = (int) $meta['affiliate_user_id'];
                }
                if (! empty($meta['affiliate_enrollment_id'])) {
                    $updates['affiliate_enrollment_id'] = (int) $meta['affiliate_enrollment_id'];
                }
                if ($updates !== []) {
                    DB::table('orders')->where('id', $row->id)->update($updates);
                }
            }
        }
    }
};
