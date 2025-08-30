<?php
// database/migrations/2025_08_30_000001_add_template_snapshot_to_campaigns.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->string('template_name')->nullable()->after('whatsapp_template_id');
            $t->string('template_language', 10)->default('id')->after('template_name');
            $t->json('variable_bindings')->nullable()->after('template_language');
            $t->json('template_components')->nullable()->after('variable_bindings');
        });

        // Opsional tapi disarankan: ubah FK agar tidak menghapus campaign saat template dihapus
        // Buat migrasi terpisah jika perlu drop & re-create constraint
        // Schema::table('campaigns', function (Blueprint $t) {
        //     $t->dropForeign(['whatsapp_template_id']);
        //     $t->foreign('whatsapp_template_id')->references('id')->on('whatsapp_templates')->nullOnDelete();
        // });
    }

    public function down(): void {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropColumn(['template_name', 'template_language', 'variable_bindings', 'template_components']);
        });
    }
};
