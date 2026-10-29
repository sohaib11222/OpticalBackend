<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('communication_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->uuid('creation_key');
            $t->char('request_hash', 64);
            $t->string('channel', 10);
            $t->json('message_ids');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['shop_id', 'creation_key']);
        });
    }
    public function down(): void { Schema::dropIfExists('communication_batches'); }
};
