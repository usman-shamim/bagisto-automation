<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('automation_webhook_deliveries', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('webhook_id')->unsigned();
            $table->string('event', 64);
            $table->json('payload');
            $table->string('signature', 128)->nullable();
            // Attempt counter; starts at 0 on dispatch, increments to 5 max.
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->integer('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->foreign('webhook_id')->references('id')->on('automation_webhooks')->onDelete('cascade');
            $table->index(['webhook_id', 'created_at']);
            $table->index('next_retry_at');
            $table->index('succeeded_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('automation_webhook_deliveries');
    }
};
