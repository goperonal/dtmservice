<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0) Pastikan engine InnoDB (FK butuh InnoDB)
        DB::statement("SET SESSION sql_require_primary_key = 0");

        // 1) Drop FK lama (kalau ada)
        $this->dropFkIfExists('broadcasts', 'whatsapp_template_id');
        $this->dropFkIfExists('campaigns',  'whatsapp_template_id');

        // 2) Samakan tipe kolom: BIGINT UNSIGNED NULL
        //    (gunakan ALTER TABLE supaya tidak perlu doctrine/dbal)
        DB::statement('ALTER TABLE `broadcasts` 
            MODIFY `whatsapp_template_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE `campaigns` 
            MODIFY `whatsapp_template_id` BIGINT UNSIGNED NULL');

        // 3) Bersihkan orphan
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

        // 4) Tambah index & FK baru: ON DELETE SET NULL
        Schema::table('broadcasts', function (Blueprint $table) {
            try { $table->index('whatsapp_template_id', 'broadcasts_template_idx'); } catch (\Throwable $e) {}
            $table->foreign('whatsapp_template_id', 'broadcasts_template_fk')
                ->references('id')->on('whatsapp_templates')
                ->nullOnDelete(); // ON DELETE SET NULL
        });

        Schema::table('campaigns', function (Blueprint $table) {
            try { $table->index('whatsapp_template_id', 'campaigns_template_idx'); } catch (\Throwable $e) {}
            $table->foreign('whatsapp_template_id', 'campaigns_template_fk')
                ->references('id')->on('whatsapp_templates')
                ->nullOnDelete(); // ON DELETE SET NULL
        });
    }

    public function down(): void
    {
        $this->dropFkIfExists('broadcasts', 'whatsapp_template_id');
        $this->dropFkIfExists('campaigns',  'whatsapp_template_id');

        // (opsional) kembalikan tipe ke BIGINT UNSIGNED NULL tetap aman
        DB::statement('ALTER TABLE `broadcasts` 
            MODIFY `whatsapp_template_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE `campaigns` 
            MODIFY `whatsapp_template_id` BIGINT UNSIGNED NULL');

        // (opsional) pasang lagi FK dengan CASCADE kalau memang mau
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->foreign('whatsapp_template_id', 'broadcasts_template_fk')
                ->references('id')->on('whatsapp_templates')
                ->cascadeOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreign('whatsapp_template_id', 'campaigns_template_fk')
                ->references('id')->on('whatsapp_templates')
                ->cascadeOnDelete();
        });
    }

    /**
     * Drop semua foreign key yang mengikat kolom $column pada tabel $table (jika ada),
     * termasuk index otomatisnya.
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

        // Drop index pada kolom (kalau ada) supaya bisa pasang ulang dengan nama yang jelas
        try {
            Schema::table($table, function (Blueprint $table) use ($column) {
                try { $table->dropIndex([$column]); } catch (\Throwable $e) {}
            });
        } catch (\Throwable $e) {}
    }
};
