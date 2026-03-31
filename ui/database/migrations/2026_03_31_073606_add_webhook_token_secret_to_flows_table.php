<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->string('webhook_token_secret', 64)
                ->nullable()
                ->after('container_id');
        });

        DB::table('flows')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($flows): void {
                foreach ($flows as $flow) {
                    DB::table('flows')
                        ->where('id', $flow->id)
                        ->update(['webhook_token_secret' => Str::random(40)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('webhook_token_secret');
        });
    }
};
