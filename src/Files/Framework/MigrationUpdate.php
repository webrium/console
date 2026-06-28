<?php

use Foxdb\Migrations\Migration;
use Foxdb\Schema;
use Foxdb\Schema\Blueprint;

class MigrationClass extends Migration
{
    /**
     * Apply the migration.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('table_name', function (Blueprint $table) {
            //
        });
    }

    /**
     * Reverse the migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('table_name', function (Blueprint $table) {
            //
        });
    }
}
