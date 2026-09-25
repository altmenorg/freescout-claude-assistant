<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClaudeAssistantLogsTable extends Migration
{
    public function up()
    {
        // One row per generated draft: token usage (cost statistics) and the agent's rating (feedback statistics).
        // No conversation content is stored here.
        Schema::create('claude_assistant_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->integer('mailbox_id')->unsigned()->index();
            $table->integer('conversation_id')->unsigned();
            $table->string('model', 64);
            $table->integer('input_tokens')->unsigned()->default(0);
            $table->integer('output_tokens')->unsigned()->default(0);
            $table->integer('cache_read_tokens')->unsigned()->default(0);
            $table->integer('cache_write_tokens')->unsigned()->default(0);
            $table->tinyInteger('rating')->nullable(); // 1 = good, -1 = needs work, null = not rated
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('claude_assistant_logs');
    }
}
