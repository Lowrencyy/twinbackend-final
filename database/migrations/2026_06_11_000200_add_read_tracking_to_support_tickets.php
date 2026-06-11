<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('requester_last_read_at')->nullable()->after('closed_at');
            $table->timestamp('admin_last_read_at')->nullable()->after('requester_last_read_at');
            $table->timestamp('last_message_at')->nullable()->after('admin_last_read_at');
            $table->foreignId('last_message_sender_id')->nullable()->after('last_message_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('support_ticket_sessions', function (Blueprint $table) {
            $table->timestamp('last_joined_at')->nullable()->after('started_at');
            $table->timestamp('admin_joined_at')->nullable()->after('last_joined_at');
            $table->timestamp('requester_joined_at')->nullable()->after('admin_joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_joined_at', 'admin_joined_at', 'requester_joined_at']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_message_sender_id');
            $table->dropColumn(['requester_last_read_at', 'admin_last_read_at', 'last_message_at']);
        });
    }
};
