<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->json('storage_snapshot')->nullable()->after('graph_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->dropColumn('storage_snapshot');
        });
    }
};
