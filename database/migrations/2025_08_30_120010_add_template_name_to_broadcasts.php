<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            if (!Schema::hasColumn('broadcasts', 'whatsapp_template_name')) {
                $table->string('whatsapp_template_name')->nullable()->after('whatsapp_template_id');
                $table->index('whatsapp_template_name');
            }
        });

        // Backfill lewat campaign snapshot (lebih stabil)
        DB::statement("
            UPDATE broadcasts b
            JOIN campaigns  c ON c.id = b.campaign_id
            SET b.whatsapp_template_name = c.whatsapp_template_name
            WHERE c.whatsapp_template_name IS NOT NULL
              AND (b.whatsapp_template_name IS NULL OR b.whatsapp_template_name = '')
        ");
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            if (Schema::hasColumn('broadcasts', 'whatsapp_template_name')) {
                $table->dropIndex(['whatsapp_template_name']);
                $table->dropColumn('whatsapp_template_name');
            }
        });
    }
};
