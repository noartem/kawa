<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table): void {
            $table->timestamp('code_updated_at')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table): void {
            $table->dropColumn(['code_updated_at']);
        });
    }
};
