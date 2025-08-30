<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_webhooks', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_webhooks', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('timestamp');
            }
            if (!Schema::hasColumn('whatsapp_webhooks', 'message_type')) {
                $table->string('message_type', 32)->nullable()->after('status');
            }
            if (!Schema::hasColumn('whatsapp_webhooks', 'message_text')) {
                $table->text('message_text')->nullable()->after('message_type');
            }
            if (!Schema::hasColumn('whatsapp_webhooks', 'media_id')) {
                $table->string('media_id', 191)->nullable()->after('message_text');
            }
            if (!Schema::hasColumn('whatsapp_webhooks', 'media_mime')) {
                $table->string('media_mime', 64)->nullable()->after('media_id');
            }
            if (!Schema::hasColumn('whatsapp_webhooks', 'media_local_url')) {
                // URL publik (mis. /storage/wa_inbox/xxx.webp) agar gampang dipakai di UI
                $table->string('media_local_url', 512)->nullable()->after('media_mime');
            }

            // index sederhana buat performa
            $table->index(['from_number', 'to_number'], 'ww_from_to_idx');
            $table->index(['read_at'], 'ww_read_at_idx');
            $table->index(['timestamp'], 'ww_ts_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_webhooks', function (Blueprint $table) {
            // aman: drop kolom tambahan
            foreach (['read_at','message_type','message_text','media_id','media_mime','media_local_url'] as $col) {
                if (Schema::hasColumn('whatsapp_webhooks', $col)) {
                    $table->dropColumn($col);
                }
            }
            // indexes (optional drop)
            try { $table->dropIndex('ww_from_to_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('ww_read_at_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('ww_ts_idx'); } catch (\Throwable $e) {}
        });
    }
};
