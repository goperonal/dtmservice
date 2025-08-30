<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Drop FK lama (kalau ada) — aman walau nama tidak diketahui
        $this->dropFkIfExists('broadcasts', 'whatsapp_template_id');
        $this->dropFkIfExists('campaigns',  'whatsapp_template_id');

        // 2) Bersihkan orphan lebih dulu
        DB::statement('
            UPDATE broadcasts b
            LEFT JOIN whatsapp_templates w ON w.id = b.whatsapp_template_id
            SET b.whatsapp_template_id = NULL
            WHERE b.whatsapp_template_id IS NOT NULL AND w.id IS NULL
        ');
        DB::statement('
            UPDATE campaigns c
            LEFT JOIN whatsapp_templates w ON w.id = c.whatsapp_template_id
            SET c.whatsapp_template_id = NULL
            WHERE c.whatsapp_template_id IS NOT NULL AND w.id IS NULL
        ');

        // 3) Tambah FK baru: ON DELETE SET NULL
        Schema::table('broadcasts', function (Blueprint $table) {
            // index (ignore kalau sudah ada)
            try { $table->index('whatsapp_template_id'); } catch (\Throwable $e) {}
            $table->foreign('whatsapp_template_id')
                ->references('id')->on('whatsapp_templates')
                ->nullOnDelete(); // ON DELETE SET NULL
        });

        Schema::table('campaigns', function (Blueprint $table) {
            try { $table->index('whatsapp_template_id'); } catch (\Throwable $e) {}
            $table->foreign('whatsapp_template_id')
                ->references('id')->on('whatsapp_templates')
                ->nullOnDelete(); // ON DELETE SET NULL
        });
    }

    public function down(): void
    {
        // Drop FK yang barusan dibuat
        $this->dropFkIfExists('broadcasts', 'whatsapp_template_id');
        $this->dropFkIfExists('campaigns',  'whatsapp_template_id');

        // (opsional) kembalikan ke CASCADE seperti semula
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->foreign('whatsapp_template_id')
                ->references('id')->on('whatsapp_templates')
                ->cascadeOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreign('whatsapp_template_id')
                ->references('id')->on('whatsapp_templates')
                ->cascadeOnDelete();
        });
    }

    /**
     * Drop semua FK yang mengikat kolom $column pada tabel $table (jika ada).
     */
    private function dropFkIfExists(string $table, string $column): void
    {
        $db = DB::getDatabaseName();

        $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME');

        foreach ($constraints as $name) {
            try {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Beberapa MySQL bikin index terpisah untuk FK — coba drop index kolomnya (aman kalau tidak ada)
        try {
            Schema::table($table, function (Blueprint $table) use ($column) {
                try { $table->dropIndex([$column]); } catch (\Throwable $e) {}
            });
        } catch (\Throwable $e) {}
    }
};
