<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pending_product_updates', function (Blueprint $table) {
            $table->string('external_request_id', 128)->nullable()->after('submitted_by_token_id');

            // Per-token idempotency. MySQL permits multiple NULLs in a unique index,
            // so a NULL external_request_id means "no idempotency requested".
            $table->unique(['submitted_by_token_id', 'external_request_id'], 'pending_updates_idempotency_unique');
        });
    }

    public function down()
    {
        Schema::table('pending_product_updates', function (Blueprint $table) {
            $table->dropUnique('pending_updates_idempotency_unique');
            $table->dropColumn('external_request_id');
        });
    }
};
