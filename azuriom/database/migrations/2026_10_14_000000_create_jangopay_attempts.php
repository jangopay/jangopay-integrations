<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jangopay_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('payment_id')->unique();
            $table->unsignedBigInteger('gateway_id');
            $table->string('checkout_key', 64)->unique();
            $table->string('order_id', 120)->unique();
            $table->string('access_hash', 64);
            $table->string('invoice_id', 120)->nullable()->unique();
            $table->string('txn_id', 120)->nullable()->unique();
            $table->string('tx_hash', 160)->nullable();
            $table->string('amount_usd', 24);
            $table->string('currency', 3)->default('USD');
            $table->string('asset', 15)->nullable();
            $table->string('chain', 32)->nullable();
            $table->string('quote_amount', 80)->nullable();
            $table->text('receive_address')->nullable();
            $table->string('remote_status', 64)->nullable();
            $table->string('state', 40)->default('reserved')->index();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('delivery_started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentional: never delete payment mapping evidence on plugin disable/rollback.
        // Remove the table only as a separately approved data-retention operation.
    }
};
