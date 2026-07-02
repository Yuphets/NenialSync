<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_url')->nullable();
        });

        Schema::create('email_verification_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('sent_at');
            $table->timestamps();
        });

        Schema::table('password_reset_tickets', function (Blueprint $table) {
            $table->text('temporary_password')->nullable();
            $table->timestamp('temporary_password_viewed_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_provider', 32)->nullable()->index();
            $table->string('provider_session_id')->nullable()->unique();
            $table->text('payment_url')->nullable();
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->json('payment_metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn([
            'payment_provider', 'provider_session_id', 'payment_url', 'payment_expires_at', 'paid_at', 'payment_metadata',
        ]));
        Schema::table('password_reset_tickets', fn (Blueprint $table) => $table->dropColumn(['temporary_password', 'temporary_password_viewed_at']));
        Schema::dropIfExists('email_verification_otps');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['google_id', 'avatar_url']));
    }
};
