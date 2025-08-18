<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Ambil semua group unik dari recipients
        $groups = DB::table('recipients')->select('group')->distinct()->pluck('group');

        foreach ($groups as $groupName) {
            if ($groupName) {
                $groupId = DB::table('groups')->insertGetId([
                    'name' => $groupName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Hubungkan recipient dengan group
                $recipients = DB::table('recipients')->where('group', $groupName)->pluck('id');
                foreach ($recipients as $recipientId) {
                    DB::table('group_recipient')->insertOrIgnore([
                        'recipient_id' => $recipientId,
                        'group_id' => $groupId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // rollback: hapus semua isi pivot dan groups
        DB::table('group_recipient')->truncate();
        DB::table('groups')->truncate();
    }
};
