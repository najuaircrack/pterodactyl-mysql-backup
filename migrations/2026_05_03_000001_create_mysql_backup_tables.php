<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mysql_backup_storage_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->nullable()->index();
            $table->string('name');
            $table->string('driver', 32);
            $table->text('config_encrypted');
            $table->boolean('is_global')->default(false)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });

        Schema::create('mysql_backup_configurations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedBigInteger('storage_provider_id')->nullable();
            $table->boolean('enabled')->default(false)->index();
            $table->json('database_ids')->nullable();
            $table->string('frequency_type', 32)->default('interval');
            $table->unsignedInteger('interval_minutes')->default(360);
            $table->string('cron_expression')->nullable();
            $table->unsignedInteger('retention_count')->default(14);
            $table->unsignedInteger('retention_days')->nullable();
            $table->boolean('compress')->default(true);
            $table->boolean('encrypt')->default(false);
            $table->json('notifications')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_queued_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('storage_provider_id')->references('id')->on('mysql_backup_storage_providers')->nullOnDelete();
        });

        Schema::create('mysql_backup_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('server_id')->index();
            $table->unsignedInteger('database_id')->nullable()->index();
            $table->unsignedBigInteger('storage_provider_id')->nullable();
            $table->unsignedInteger('requested_by')->nullable()->index();
            $table->string('database_name');
            $table->string('status', 32)->default('queued')->index();
            $table->string('filename');
            $table->string('path');
            $table->string('checksum_sha256')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('compressed')->default(true);
            $table->boolean('encrypted')->default(false);
            $table->boolean('manual')->default(false);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('database_id')->references('id')->on('databases')->nullOnDelete();
            $table->foreign('storage_provider_id')->references('id')->on('mysql_backup_storage_providers')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('mysql_backup_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backup_record_id')->nullable()->index();
            $table->unsignedInteger('server_id')->index();
            $table->string('level', 16)->default('info');
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign('backup_record_id')->references('id')->on('mysql_backup_records')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mysql_backup_logs');
        Schema::dropIfExists('mysql_backup_records');
        Schema::dropIfExists('mysql_backup_configurations');
        Schema::dropIfExists('mysql_backup_storage_providers');
    }
};
