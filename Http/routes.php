<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\ClaudeAssistant\Http\Controllers'], function () {
    // 20 drafts per minute and per agent: protects the API budget against a stuck key or script
    Route::post('/claude-assistant/draft', ['uses' => 'AssistantController@draft', 'middleware' => ['auth', 'roles', 'throttle:20,1'], 'roles' => ['admin', 'user']])->name('claudeassistant.draft');
    Route::post('/claude-assistant/feedback', ['uses' => 'AssistantController@feedback', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('claudeassistant.feedback');
    Route::post('/claude-assistant/refresh-docs', ['uses' => 'AssistantController@refreshDocs', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('claudeassistant.refresh_docs');
});
