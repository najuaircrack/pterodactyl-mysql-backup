<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mysql_backup_storage_providers', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('enabled');
            $table->string('last_test_status', 32)->nullable()->after('last_tested_at');
            $table->text('last_test_message')->nullable()->after('last_test_status');
        });

        Schema::table('mysql_backup_records', function (Blueprint $table) {
            $table->string('stage', 32)->nullable()->after('status');
            $table->boolean('verified')->default(false)->after('encrypted')->index();
            $table->timestamp('verified_at')->nullable()->after('verified');
            $table->unsignedInteger('duration_ms')->nullable()->after('progress');
            $table->boolean('safety_backup')->default(false)->after('manual')->index();
            $table->uuid('parent_backup_uuid')->nullable()->after('safety_backup')->index();
        });

        Schema::create('mysql_backup_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->nullable()->index();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('backup_record_id')->nullable()->index();
            $table->string('event', 80)->index();
            $table->ipAddress('ip_address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('backup_record_id')->references('id')->on('mysql_backup_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mysql_backup_audit_logs');

        Schema::table('mysql_backup_records', function (Blueprint $table) {
            $table->dropColumn([
                'stage',
                'verified',
                'verified_at',
                'duration_ms',
                'safety_backup',
                'parent_backup_uuid',
            ]);
        });

        Schema::table('mysql_backup_storage_providers', function (Blueprint $table) {
            $table->dropColumn([
                'last_tested_at',
                'last_test_status',
                'last_test_message',
            ]);
        });
    }
};
