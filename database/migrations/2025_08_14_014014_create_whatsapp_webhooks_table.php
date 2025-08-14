<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_webhooks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('broadcast_id')->nullable()->constrained('broadcasts')->onDelete('cascade');
            $table->enum('event_type', ['message', 'status']);
            $table->string('message_id', 191)->nullable()->index(); // wamid
            $table->string('status', 50)->nullable()->index();
            $table->string('from_number', 20)->nullable()->index();
            $table->string('to_number', 20)->nullable()->index();
            $table->string('conversation_id', 100)->nullable()->index();
            $table->string('conversation_category', 50)->nullable();
            $table->string('pricing_model', 50)->nullable();
            $table->timestamp('timestamp')->nullable()->index();
            $table->longText('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhooks');
    }
};
