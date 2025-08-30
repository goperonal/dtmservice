<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0) (Opsional) Selaraskan tipe kolom agar match ke whatsapp_templates.id (bigint unsigned nullable)
        Schema::table('broadcasts', function (Blueprint $table) {
            try {
                $table->unsignedBigInteger('whatsapp_template_id')->nullable()->change();
            } catch (\Throwable $e) {
                // abaikan jika driver tidak support change() atau sudah sesuai
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            try {
                $table->unsignedBigInteger('whatsapp_template_id')->nullable()->change();
            } catch (\Throwable $e) {}
        });

        // 1) Drop semua FK lama untuk kolom terkait (aman bila tidak ada)
        $this->dropFkIfExists('broadcasts', 'whatsapp_template_id');
        $this->dropFkIfExists('campaigns',  'whatsapp_template_id');

        // 2) Bersihkan orphan rows sebelum pasang FK baru
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

        // 3) Tambah index & pasang FK baru: ON DELETE SET NULL
        Schema::table('broadcasts', function (Blueprint $table) {
            try { $table->index('whatsapp_template_id'); } catch (\Throwable $e) {}
            $table->foreign('whatsapp_template_id', 'broadcasts_whatsapp_template_id_fk')
                ->references('id')->on('whatsapp_templates')
                ->nullOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            try { $table->index('whatsapp_template_id'); } catch (\Throwable $e) {}
            $table->foreign('whatsapp_template_id', 'campaigns_whatsapp_template_id_fk')
                ->references('id')->on('whatsapp_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Lepas FK yang barusan dibuat
        $this->dropFkIfExists('broadcasts', 'whatsapp_template_id');
        $this->dropFkIfExists('campaigns',  'whatsapp_template_id');

        // (opsional) kembalikan ke cascade
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->foreign('whatsapp_template_id', 'broadcasts_whatsapp_template_id_fk')
                ->references('id')->on('whatsapp_templates')
                ->cascadeOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreign('whatsapp_template_id', 'campaigns_whatsapp_template_id_fk')
                ->references('id')->on('whatsapp_templates')
                ->cascadeOnDelete();
        });
    }

    /**
     * Drop semua foreign key yang terkait dengan kolom $column pada $table.
     * Juga coba drop index kolomnya jika dibuat otomatis.
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

        // Drop index yang menempel di kolom (kalau ada)
        try {
            Schema::table($table, function (Blueprint $t) use ($column) {
                try { $t->dropIndex([$column]); } catch (\Throwable $e) {}
            });
        } catch (\Throwable $e) {}
    }
};
