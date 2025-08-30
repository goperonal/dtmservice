<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // NO-OP: sengaja dikosongkan agar tidak lagi menambah FK yang salah.
        // FK yang benar akan dipasang oleh migration baru: 2025_08_31_000001_fix_template_fks.php
    }

    public function down(): void
    {
        // NO-OP.
    }
};
