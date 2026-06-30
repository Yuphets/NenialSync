<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('source_node_id', 80)->nullable()->index();
            $table->string('local_reference')->nullable();
            $table->timestamp('synced_at')->nullable();
        });

        Schema::create('sync_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 60)->index();
            $table->string('aggregate_type', 60);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['event_type', 'aggregate_type', 'aggregate_id']);
        });

        Schema::create('sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('node_id', 80);
            $table->uuid('event_id');
            $table->string('event_type', 60);
            $table->nullableMorphs('result');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['node_id', 'event_id']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbox_id')->nullable()->constrained('sync_outbox')->nullOnDelete();
            $table->uuid('event_id')->index();
            $table->string('event_type', 60);
            $table->string('reason');
            $table->json('local_payload');
            $table->json('remote_response')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_receipts');
        Schema::dropIfExists('sync_outbox');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['source_node_id', 'local_reference', 'synced_at']);
        });
    }
};
