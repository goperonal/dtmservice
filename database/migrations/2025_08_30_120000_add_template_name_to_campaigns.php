<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'whatsapp_template_name')) {
                $table->string('whatsapp_template_name')->nullable()->after('whatsapp_template_id');
                $table->index('whatsapp_template_name');
            }
        });

        // Backfill dari FK id (kalau masih ada datanya)
        DB::statement("
            UPDATE campaigns c
            JOIN whatsapp_templates wt ON wt.id = c.whatsapp_template_id
            SET c.whatsapp_template_name = wt.name
            WHERE c.whatsapp_template_id IS NOT NULL
              AND (c.whatsapp_template_name IS NULL OR c.whatsapp_template_name = '')
        ");

        // (opsional) selaraskan dengan snapshot yang sudah ada
        DB::statement("
            UPDATE campaigns
            SET whatsapp_template_name = COALESCE(template_name, whatsapp_template_name)
            WHERE template_name IS NOT NULL AND template_name <> ''
        ");
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'whatsapp_template_name')) {
                $table->dropIndex(['whatsapp_template_name']);
                $table->dropColumn('whatsapp_template_name');
            }
        });
    }
};
