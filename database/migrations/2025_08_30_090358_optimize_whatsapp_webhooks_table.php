<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_webhooks', function (Blueprint $table) {
            // Tambah timestamps jika belum ada
            if (! Schema::hasColumn('whatsapp_webhooks', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('payload');
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        // (Opsional tapi dianjurkan) Backfill created_at agar latestOfMany() akurat untuk data lama
        // Isi created_at yang null dengan updated_at atau NOW()
        DB::table('whatsapp_webhooks')
            ->whereNull('created_at')
            ->update(['created_at' => DB::raw('COALESCE(updated_at, NOW())')]);

        // Tambah index komposit jika belum ada
        $this->ensureIndex(
            table: 'whatsapp_webhooks',
            indexName: 'whatsapp_webhooks_message_id_created_at_index',
            columns: ['message_id', 'created_at']
        );

        $this->ensureIndex(
            table: 'whatsapp_webhooks',
            indexName: 'whatsapp_webhooks_broadcast_id_created_at_index',
            columns: ['broadcast_id', 'created_at']
        );
    }

    public function down(): void
    {
        // Hapus index komposit kalau ada (timestamps biarkan)
        $this->dropIndexIfExists('whatsapp_webhooks', 'whatsapp_webhooks_message_id_created_at_index');
        $this->dropIndexIfExists('whatsapp_webhooks', 'whatsapp_webhooks_broadcast_id_created_at_index');
    }

    /**
     * Buat index jika belum ada (tanpa Doctrine).
     */
    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        if (! $this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        }
    }

    /**
     * Hapus index jika ada (tanpa Doctrine).
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }
    }

    /**
     * Cek keberadaan index via INFORMATION_SCHEMA (MySQL/MariaDB).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $dbName = DB::getDatabaseName();

        $row = DB::selectOne(
            'SELECT COUNT(1) AS c
               FROM information_schema.statistics
              WHERE table_schema = ?
                AND table_name   = ?
                AND index_name   = ?',
            [$dbName, $table, $indexName]
        );

        return (isset($row->c) && (int) $row->c > 0);
    }
};
