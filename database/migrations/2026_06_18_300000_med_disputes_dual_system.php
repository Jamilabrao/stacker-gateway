<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('med_disputes', function (Blueprint $table) {
            $table->string('responsible_party', 16)->default('tenant')->index()->after('tenant_id');
            $table->text('reason')->nullable()->after('txid');
            $table->string('reason_code', 64)->nullable()->after('reason');
            $table->string('defense_dossier_path', 512)->nullable()->after('defense_text');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable()->index()->after('resolved_at');
            $table->text('resolution_note')->nullable()->after('resolved_by_user_id');
        });

        if (Schema::hasTable('med_disputes') && Schema::hasTable('orders')) {
            DB::table('med_disputes')
                ->where('responsible_party', 'tenant')
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        $order = DB::table('orders')->where('id', $row->order_id)->first();
                        if ($order === null) {
                            continue;
                        }
                        $meta = json_decode($order->metadata ?? '{}', true);
                        $isApiPix = $order->api_application_id !== null
                            && $order->payment_method === 'pix'
                            && is_array($meta)
                            && ($meta['source'] ?? null) === 'api';

                        DB::table('med_disputes')
                            ->where('id', $row->id)
                            ->update([
                                'responsible_party' => $isApiPix ? 'tenant' : 'platform',
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('med_disputes', function (Blueprint $table) {
            $table->dropColumn([
                'responsible_party',
                'reason',
                'reason_code',
                'defense_dossier_path',
                'resolved_by_user_id',
                'resolution_note',
            ]);
        });
    }
};
