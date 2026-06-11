<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SupportTicket model uses the SoftDeletes trait, but the original
     * create_support_tickets_table migration only added timestamps() (no
     * deleted_at). Every query therefore failed with:
     *   "Unknown column 'support_tickets.deleted_at'".
     * This adds the missing soft-delete column.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('support_tickets', 'deleted_at')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('support_tickets', 'deleted_at')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
