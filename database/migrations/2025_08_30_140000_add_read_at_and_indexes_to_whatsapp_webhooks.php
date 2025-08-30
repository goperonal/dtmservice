<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Tambah kolom read_at kalau belum ada
        Schema::table('whatsapp_webhooks', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_webhooks', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('timestamp');
            }
        });

        // 2) Tambah index per kolom, tapi cek dulu via SHOW INDEX
        $table = 'whatsapp_webhooks';

        $ensureIndex = function (string $column, string $name) use ($table) {
            // Cek apakah sudah ada index apapun di kolom tsb
            $exists = DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ?", [$column]);
            if (!empty($exists)) {
                return; // sudah ada index di kolom ini — skip
            }
            // bikin index dengan nama yang pasti unik/pendek
            Schema::table($table, function (Blueprint $t) use ($column, $name) {
                $t->index($column, $name);
            });
        };

        $ensureIndex('event_type',   'ww_event_type_idx');
        $ensureIndex('from_number',  'ww_from_number_idx');
        $ensureIndex('to_number',    'ww_to_number_idx');
        $ensureIndex('timestamp',    'ww_timestamp_idx');
    }

    public function down(): void
    {
        // aman: drop index kalau ada, ignore error kalau tidak ada
        try { Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('ww_event_type_idx')); } catch (\Throwable $e) {}
        try { Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('ww_from_number_idx')); } catch (\Throwable $e) {}
        try { Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('ww_to_number_idx')); } catch (\Throwable $e) {}
        try { Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('ww_timestamp_idx')); } catch (\Throwable $e) {}

        if (Schema::hasColumn('whatsapp_webhooks', 'read_at')) {
            Schema::table('whatsapp_webhooks', function (Blueprint $table) {
                $table->dropColumn('read_at');
            });
        }
    }
};
