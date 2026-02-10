<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds individual indexes on x and y coordinates for stars and planets
     * to improve map chunk query performance. The unique composite index
     * on (x, y) already exists but individual indexes help the optimizer
     * with range queries used by the chunk API.
     */
    public function up(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            if (! $this->hasIndex('stars', 'stars_x_index')) {
                $table->index('x', 'stars_x_index');
            }
            if (! $this->hasIndex('stars', 'stars_y_index')) {
                $table->index('y', 'stars_y_index');
            }
        });

        Schema::table('planets', function (Blueprint $table) {
            if (! $this->hasIndex('planets', 'planets_x_index')) {
                $table->index('x', 'planets_x_index');
            }
            if (! $this->hasIndex('planets', 'planets_y_index')) {
                $table->index('y', 'planets_y_index');
            }
            if (! $this->hasIndex('planets', 'planets_user_id_index')) {
                $table->index('user_id', 'planets_user_id_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            if ($this->hasIndex('stars', 'stars_x_index')) {
                $table->dropIndex('stars_x_index');
            }
            if ($this->hasIndex('stars', 'stars_y_index')) {
                $table->dropIndex('stars_y_index');
            }
        });

        Schema::table('planets', function (Blueprint $table) {
            if ($this->hasIndex('planets', 'planets_x_index')) {
                $table->dropIndex('planets_x_index');
            }
            if ($this->hasIndex('planets', 'planets_y_index')) {
                $table->dropIndex('planets_y_index');
            }
            if ($this->hasIndex('planets', 'planets_user_id_index')) {
                $table->dropIndex('planets_user_id_index');
            }
        });
    }

    /**
     * Check if an index exists on a table.
     * Supports both MySQL (SHOW INDEX) and SQLite (pragma index_list).
     */
    protected function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select(
                "PRAGMA index_list(`{$table}`)"
            );

            foreach ($indexes as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        // MySQL / MariaDB
        $indexes = $connection->select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );

        return count($indexes) > 0;
    }
};
