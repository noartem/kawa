<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->text('code')->nullable();
            $table->string('status')->default('draft');
            $table->string('container_id')->nullable()->index();
            $table->string('entrypoint')->default('main.py');
            $table->string('image')->default('flow:dev');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_runs', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->string('container_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_logs', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_key')->nullable()->index();
            $table->string('level')->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_logs');
        Schema::dropIfExists('flow_runs');
        Schema::dropIfExists('flows');
    }
};
