<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add minimal single-owner invariant columns to the existing users table.
     *
     * `is_owner` enforces fail-closed authorization at the database layer, while
     * the nullable unique `owner_slot` prevents concurrent provisioning processes
     * from creating more than one owner across both SQLite and MySQL.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('password');
            $table->unsignedTinyInteger('owner_slot')->nullable()->unique()->after('is_owner');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['owner_slot']);
            $table->dropColumn(['is_owner', 'owner_slot']);
        });
    }
};
