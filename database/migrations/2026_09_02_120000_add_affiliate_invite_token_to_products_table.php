<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'affiliate_invite_token')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('affiliate_invite_token', 64)->nullable()->unique();
        });

        if (! Schema::hasColumn('products', 'affiliate_enabled')) {
            return;
        }

        $query = DB::table('products')->where('affiliate_enabled', true)->whereNull('affiliate_invite_token');
        if (Schema::hasColumn('products', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        foreach ($query->pluck('id') as $id) {
            do {
                $token = Str::lower(Str::random(40));
            } while (DB::table('products')->where('affiliate_invite_token', $token)->exists());

            DB::table('products')->where('id', $id)->update(['affiliate_invite_token' => $token]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'affiliate_invite_token')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('affiliate_invite_token');
        });
    }
};
