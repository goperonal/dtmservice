<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private function indexExists(string $table, string $index): bool
    {
        $db = DB::getDatabaseName();
        $res = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$db, $table, $index]
        );
        return !empty($res);
    }

    public function up(): void
    {
        Schema::table('whatsapp_webhooks', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_webhooks','message_type')) {
                $table->string('message_type', 32)->nullable()->after('status');
            }
            if (!Schema::hasColumn('whatsapp_webhooks','media_id')) {
                $table->string('media_id', 191)->nullable()->after('message_type');
            }
            if (!Schema::hasColumn('whatsapp_webhooks','media_mime')) {
                $table->string('media_mime', 64)->nullable()->after('media_id');
            }
            if (!Schema::hasColumn('whatsapp_webhooks','media_path')) {
                $table->string('media_path', 255)->nullable()->after('media_mime');
            }
            if (!Schema::hasColumn('whatsapp_webhooks','media_size')) {
                $table->unsignedBigInteger('media_size')->nullable()->after('media_path');
            }
            if (!Schema::hasColumn('whatsapp_webhooks','media_fetched')) {
                $table->boolean('media_fetched')->default(false)->after('media_size');
            }
        });

        // Tambah index hanya jika belum ada
        if (! $this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_from_number_to_number_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->index(['from_number','to_number']));
        }
        if (! $this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_read_at_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->index('read_at'));
        }
        if (! $this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_timestamp_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->index('timestamp'));
        }
        if (! $this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_media_fetched_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->index('media_fetched'));
        }
    }

    public function down(): void
    {
        // Drop index hanya jika ada
        if ($this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_from_number_to_number_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('whatsapp_webhooks_from_number_to_number_index'));
        }
        if ($this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_read_at_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('whatsapp_webhooks_read_at_index'));
        }
        if ($this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_timestamp_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('whatsapp_webhooks_timestamp_index'));
        }
        if ($this->indexExists('whatsapp_webhooks', 'whatsapp_webhooks_media_fetched_index')) {
            Schema::table('whatsapp_webhooks', fn (Blueprint $t) => $t->dropIndex('whatsapp_webhooks_media_fetched_index'));
        }

        Schema::table('whatsapp_webhooks', function (Blueprint $table) {
            foreach (['media_fetched','media_size','media_path','media_mime','media_id','message_type'] as $col) {
                if (Schema::hasColumn('whatsapp_webhooks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
