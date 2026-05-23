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
        Schema::create('pending_product_updates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id')->unsigned();
            $table->integer('submitted_by_token_id')->unsigned()->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->decimal('proposed_price', 12, 4)->nullable();
            $table->integer('proposed_stock')->nullable();
            $table->decimal('current_price_snapshot', 12, 4)->nullable();
            $table->integer('current_stock_snapshot')->nullable();
            $table->json('flags')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'applied', 'failed'])->default('pending');
            $table->integer('reviewed_by_admin_id')->unsigned()->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('submitted_by_token_id')->references('id')->on('api_admin_tokens')->onDelete('set null');
            $table->foreign('reviewed_by_admin_id')->references('id')->on('admins')->onDelete('set null');
            $table->index(['status', 'created_at']);
            $table->index(['product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pending_product_updates');
    }
};
