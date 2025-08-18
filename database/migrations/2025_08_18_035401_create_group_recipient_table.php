<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_recipient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained()->onDelete('cascade');
            $table->foreignId('group_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['recipient_id', 'group_id']); // biar ga dobel
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_recipient');
    }
};
