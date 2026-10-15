<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('jangopay_attempts', function (Blueprint $table) {
            $table->text('allowed_assets')->nullable();
            $table->timestamp('last_checked_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Preserve payment evidence and review metadata even after plugin disable.
    }
};
