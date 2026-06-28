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
        Schema::create('table_name', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('table_name');
    }
}
