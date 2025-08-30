<?php
// database/migrations/2025_08_30_000002_extend_broadcasts_for_sending.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('broadcasts', function (Blueprint $t) {
            if (!Schema::hasColumn('broadcasts', 'to'))   $t->string('to', 32)->nullable()->after('recipient_id');
            if (!Schema::hasColumn('broadcasts', 'body')) $t->text('body')->nullable()->after('to');

            $t->index(['campaign_id']);
            $t->index(['status']);
            $t->index(['campaign_id', 'status']);

            // Cegah penerima dobel dalam 1 campaign (jika belum ada)
            $t->unique(['campaign_id', 'recipient_id'], 'broadcasts_campaign_recipient_unique');
        });
    }

    public function down(): void {
        Schema::table('broadcasts', function (Blueprint $t) {
            if (Schema::hasColumn('broadcasts', 'body')) $t->dropColumn('body');
            if (Schema::hasColumn('broadcasts', 'to'))   $t->dropColumn('to');
            $t->dropIndex(['campaign_id']);
            $t->dropIndex(['status']);
            $t->dropIndex(['campaign_id', 'status']);
            $t->dropUnique('broadcasts_campaign_recipient_unique');
        });
    }
};
