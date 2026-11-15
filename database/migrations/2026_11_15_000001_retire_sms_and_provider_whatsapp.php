<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keep the old rows as an auditable communication history, but make it
     * impossible for a delayed worker to submit an SMS or provider WhatsApp
     * message after the CRM moves to email + WhatsApp Web click-to-chat.
     */
    public function up(): void
    {
        DB::table('customer_messages')
            ->whereIn('channel', ['sms', 'whatsapp'])
            ->whereIn('status', ['scheduled', 'blocked', 'retry_wait'])
            ->update([
                'status' => 'cancelled',
                'error_code' => 'channel_retired',
                'error_message' => 'SMS and automated WhatsApp delivery were retired. Use WhatsApp Web click-to-chat.',
                'updated_at' => now(),
            ]);

        DB::table('message_templates')
            ->whereIn('channel', ['sms', 'whatsapp'])
            ->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Historical messages are intentionally not revived automatically.
    }
};
