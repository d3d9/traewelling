<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::table('travel_chains', static function(Blueprint $table) {
            $table->text('body')->nullable()->default(null)->change();
        });
    }

    public function down(): void {
        Schema::table('travel_chains', static function(Blueprint $table) {
            $table->text('body')->nullable(false)->default(null)->change();
        });
    }
};
