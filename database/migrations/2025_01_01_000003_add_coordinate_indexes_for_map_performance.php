<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite indexes on (x, y) coordinates for stars and planets
     * to improve map chunk query performance. The existing unique index
     * on (x, y) is already present but we add individual indexes for
     * range queries used by the chunk API.
     */
    public function up(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->index('x', 'stars_x_index');
            $table->index('y', 'stars_y_index');
        });

        Schema::table('planets', function (Blueprint $table) {
            $table->index('x', 'planets_x_index');
            $table->index('y', 'planets_y_index');
            $table->index('user_id', 'planets_user_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->dropIndex('stars_x_index');
            $table->dropIndex('stars_y_index');
        });

        Schema::table('planets', function (Blueprint $table) {
            $table->dropIndex('planets_x_index');
            $table->dropIndex('planets_y_index');
            $table->dropIndex('planets_user_id_index');
        });
    }
};
