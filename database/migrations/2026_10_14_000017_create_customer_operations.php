<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('shop_id')->constrained()->restrictOnDelete();
        $t->unique(['shop_id', 'id']);
    }

    private function customer(Blueprint $t, bool $nullable = false): void
    {
        $t->unsignedBigInteger('customer_id')->nullable($nullable);
        $t->foreign(['shop_id', 'customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $t) {
            $this->base($t);
            $this->customer($t);
            $t->unsignedBigInteger('order_id')->nullable();
            $t->foreign(['shop_id', 'customer_id', 'order_id'])->references(['shop_id', 'customer_id', 'id'])->on('orders')->restrictOnDelete();
            $t->unique(['shop_id', 'order_id']);
            $t->string('reference', 120);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('price_decimals');
            $t->unsignedBigInteger('amount_minor');
            $t->date('due_on');
            $t->string('status', 20)->default('open');
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->unique(['shop_id', 'creation_key']);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['shop_id', 'status', 'due_on']);
        });
        Schema::create('payment_entries', function (Blueprint $t) {
            $this->base($t);
            $t->unsignedBigInteger('receivable_id');
            $t->foreign(['shop_id', 'receivable_id'])->references(['shop_id', 'id'])->on('receivables')->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('method', 30);
            $t->unsignedBigInteger('amount_minor');
            $t->date('paid_on');
            $t->string('reference', 150)->nullable();
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->foreign(['shop_id', 'reverses_id'])->references(['shop_id', 'id'])->on('payment_entries')->restrictOnDelete();
            $t->unique(['shop_id', 'reverses_id']);
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->unique(['shop_id', 'creation_key']);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('message_templates', function (Blueprint $t) {
            $this->base($t);
            $t->string('name', 120);
            $t->string('channel', 20);
            $t->string('purpose', 20);
            $t->string('subject', 200)->nullable();
            $t->text('body');
            $t->string('content_sid', 34)->nullable();
            $t->json('content_variables')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('followup_rules', function (Blueprint $t) {
            $this->base($t);
            $t->string('name', 120);
            $t->string('trigger', 30);
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->unique(['shop_id', 'creation_key']);
            $t->unsignedBigInteger('category_id')->nullable();
            $t->foreign(['shop_id', 'category_id'])->references(['shop_id', 'id'])->on('product_categories')->restrictOnDelete();
            $t->unsignedInteger('offset');
            $t->string('unit', 10);
            $t->boolean('active')->default(true);
            $t->date('starts_on');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('template_id')->nullable();
            $t->foreign(['shop_id', 'template_id'])->references(['shop_id', 'id'])->on('message_templates')->restrictOnDelete();
            $t->unsignedInteger('lead_days')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('reminders', function (Blueprint $t) {
            $this->base($t);
            $this->customer($t, true);
            $t->string('kind', 30);
            $t->string('title', 150);
            $t->date('due_on');
            $t->string('status', 20)->default('open');
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('automation_error', 100)->nullable();
            $t->string('source_key', 190);
            $t->unique(['shop_id', 'source_key']);
            $t->unsignedBigInteger('optical_record_id')->nullable();
            $t->foreign(['shop_id', 'customer_id', 'optical_record_id'])->references(['shop_id', 'customer_id', 'id'])->on('optical_records')->restrictOnDelete();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->foreign(['shop_id', 'customer_id', 'order_id'])->references(['shop_id', 'customer_id', 'id'])->on('orders')->restrictOnDelete();
            $t->unsignedBigInteger('appointment_id')->nullable();
            $t->foreign(['shop_id', 'appointment_id'])->references(['shop_id', 'id'])->on('appointments')->restrictOnDelete();
            $t->unsignedBigInteger('rule_id')->nullable();
            $t->foreign(['shop_id', 'rule_id'])->references(['shop_id', 'id'])->on('followup_rules')->restrictOnDelete();
            $t->unsignedBigInteger('template_id')->nullable();
            $t->foreign(['shop_id', 'template_id'])->references(['shop_id', 'id'])->on('message_templates')->restrictOnDelete();
            $t->unsignedInteger('lead_days')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->text('completion_note')->nullable();
            $t->timestamps();
            $t->index(['shop_id', 'status', 'kind', 'due_on']);
        });
        Schema::create('communication_consents', function (Blueprint $t) {
            $this->base($t);
            $this->customer($t);
            $t->string('channel', 20);
            $t->string('purpose', 20);
            $t->boolean('granted');
            $t->char('recipient_hash', 64);
            $t->text('evidence');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at');
            $t->unique(['shop_id', 'customer_id', 'channel', 'purpose']);
        });
        Schema::create('consent_events', function (Blueprint $t) {
            $this->base($t);
            $this->customer($t);
            $t->string('channel', 20);
            $t->string('purpose', 20);
            $t->boolean('granted');
            $t->char('recipient_hash', 64);
            $t->text('evidence');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('customer_messages', function (Blueprint $t) {
            $this->base($t);
            $this->customer($t);
            $t->uuid('public_id')->unique();
            $t->string('dedupe_key', 190);
            $t->char('request_hash', 64);
            $t->unique(['shop_id', 'dedupe_key']);
            $t->string('channel', 20);
            $t->string('purpose', 20);
            $t->string('recipient', 254);
            $t->string('subject', 200)->nullable();
            $t->text('body');
            $t->string('content_sid', 34)->nullable();
            $t->json('content_variables')->nullable();
            $t->unsignedBigInteger('template_id')->nullable();
            $t->foreign(['shop_id', 'template_id'])->references(['shop_id', 'id'])->on('message_templates')->restrictOnDelete();
            $t->unsignedBigInteger('reminder_id')->nullable();
            $t->foreign(['shop_id', 'reminder_id'])->references(['shop_id', 'id'])->on('reminders')->restrictOnDelete();
            $t->timestamp('scheduled_at');
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('status', 30)->default('scheduled');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('provider_id', 120)->nullable()->unique();
            $t->string('error_code', 80)->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'scheduled_at', 'next_attempt_at']);
        });
        Schema::create('message_events', function (Blueprint $t) {
            $this->base($t);
            $t->unsignedBigInteger('message_id');
            $t->foreign(['shop_id', 'message_id'])->references(['shop_id', 'id'])->on('customer_messages')->restrictOnDelete();
            $t->string('status', 30);
            $t->string('code', 80)->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('messaging_suppressions', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 20);
            $t->char('recipient_hash', 64);
            $t->unique(['channel', 'recipient_hash']);
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['messaging_suppressions', 'message_events', 'customer_messages', 'consent_events', 'communication_consents', 'reminders', 'followup_rules', 'message_templates', 'payment_entries', 'receivables'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
