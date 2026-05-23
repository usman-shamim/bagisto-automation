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
        Schema::create('automation_audit_log', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('token_id')->unsigned();
            $table->integer('admin_id')->unsigned()->nullable();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->char('payload_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->smallInteger('status_code')->unsigned();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('token_id')->references('id')->on('api_admin_tokens')->onDelete('cascade');
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('set null');
            $table->index(['token_id', 'created_at']);
            $table->index(['endpoint', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('automation_audit_log');
    }
};
