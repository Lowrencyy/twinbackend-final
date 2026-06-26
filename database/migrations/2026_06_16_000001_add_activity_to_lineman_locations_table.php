<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineman_locations', function (Blueprint $table) {
            $table->string('activity')->nullable()->after('region_name');
            $table->unsignedBigInteger('pole_id')->nullable()->after('activity');
            $table->string('pole_code')->nullable()->after('pole_id');
            $table->unsignedBigInteger('node_id')->nullable()->after('pole_code');
            $table->string('node_name')->nullable()->after('node_id');
            $table->unsignedBigInteger('area_id')->nullable()->after('node_name');
        });
    }

    public function down(): void
    {
        Schema::table('lineman_locations', function (Blueprint $table) {
            $table->dropColumn(['activity', 'pole_id', 'pole_code', 'node_id', 'node_name', 'area_id']);
        });
    }
};
