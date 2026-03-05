<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->longText('code_snapshot')->nullable()->after('events');
            $table->json('graph_snapshot')->nullable()->after('code_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->dropColumn(['code_snapshot', 'graph_snapshot']);
        });
    }
};
