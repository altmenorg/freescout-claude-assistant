<?php

namespace Modules\ClaudeAssistant\Services;

/**
 * Minimal client for the Claude Messages API (POST /v1/messages).
 *
 * Plain cURL on purpose: Anthropic's official PHP SDK requires PHP 8.1, while FreeScout runs on PHP 7.1 and later,
 * and FreeScout modules can't bring their own Composer dependencies.
 */
class Claude
{
    const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const VERSION = '2023-06-01';

    /**
     * @param string $system  stable instructions and knowledge (cached: the next drafts of the day reuse it cheaply)
     * @param string $user    the ticket and the agent's request
     * @return array ['text' => string, 'usage' => array]
     * @throws \RuntimeException with a message that can be shown to the agent
     */
    public static function complete($model, $max_tokens, $system, $user)
    {
        $key = Settings::apiKey();
        if (!$key) {
            throw new \RuntimeException(__('Claude Assistant is not configured: add an API key in Manage › Settings › Claude Assistant.'));
        }
        $body = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];
        $headers = [
            'content-type: application/json',
            'x-api-key: '.$key,
            'anthropic-version: '.self::VERSION,
        ];
        // A support reply doesn't need the deepest reasoning: medium effort answers faster and costs less.
        // (Haiku 4.5 doesn't take the effort parameter.)
        if ($model !== 'claude-haiku-4-5') {
            $body['output_config'] = ['effort' => 'medium'];
        }
        // If Opus 5's safety classifiers decline a request (a support e-mail can mention anything), the API re-runs
        // it on Anthropic's recommended fallback model instead of returning a refusal.
        if ($model === 'claude-opus-5') {
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            \Log::error('[ClaudeAssistant] connection error: '.$curl_error);
            throw new \RuntimeException(__('Could not reach Claude. Please try again.'));
        }
        $data = json_decode($raw, true);
        if ($status !== 200 || !is_array($data)) {
            $api_message = is_array($data) ? ($data['error']['message'] ?? '') : '';
            \Log::error('[ClaudeAssistant] API error '.$status.': '.$api_message);
            if ($status === 401) {
                throw new \RuntimeException(__('The Claude API key is invalid.'));
            }
            if ($status === 429) {
                throw new \RuntimeException(__('Too many requests to Claude for now. Please try again in a minute.'));
            }
            if ($status === 529 || $status >= 500) {
                throw new \RuntimeException(__('Claude is overloaded right now. Please try again in a moment.'));
            }
            throw new \RuntimeException(__('Claude returned an error.').($api_message ? ' '.$api_message : ''));
        }
        if (($data['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException(__('Claude declined to write a reply for this conversation.'));
        }
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        if (trim($text) === '') {
            throw new \RuntimeException(__('Claude returned an empty reply. Please try again.'));
        }
        return ['text' => trim($text), 'usage' => $data['usage'] ?? [], 'model' => $data['model'] ?? $model];
    }
}
