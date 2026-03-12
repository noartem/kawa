<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('flows', 'graph') ? 'graph' : null,
            Schema::hasColumn('flows', 'graph_generated_at')
                ? 'graph_generated_at'
                : null,
        ]));

        if ($columns === []) {
            return;
        }

        Schema::table('flows', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('flows', 'graph')) {
            Schema::table('flows', function (Blueprint $table): void {
                $table->json('graph')->nullable()->after('code_updated_at');
            });
        }

        if (! Schema::hasColumn('flows', 'graph_generated_at')) {
            Schema::table('flows', function (Blueprint $table): void {
                $table->timestamp('graph_generated_at')->nullable()->after('graph');
            });
        }
    }
};
