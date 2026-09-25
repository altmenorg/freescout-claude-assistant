<?php

namespace Modules\ClaudeAssistant\Services;

/**
 * Claude Assistant settings (Manage > Settings > Claude Assistant), stored as FreeScout options.
 */
class Settings
{
    const PREFIX = 'claudeassistant.';

    /** Models offered in the settings: id => [label, input $/MTok, output $/MTok] (used for the cost estimate). */
    const MODELS = [
        'claude-opus-5'    => ['Claude Opus 5', 5.0, 25.0],
        'claude-sonnet-5'  => ['Claude Sonnet 5', 2.0, 10.0],
        'claude-haiku-4-5' => ['Claude Haiku 4.5', 1.0, 5.0],
    ];
    const DEFAULT_MODEL = 'claude-opus-5';

    /** Boolean options and their default value. */
    const FLAGS = [
        'use_saved_replies' => 1,
        'use_history'       => 1,
        'use_tone'          => 1,
        'mask_personal'     => 0,
        'feedback'          => 1,
    ];

    public static function get($key, $default = null)
    {
        $value = \Option::get(self::PREFIX.$key, null);
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function flag($key)
    {
        return (bool)(int)self::get($key, self::FLAGS[$key] ?? 0);
    }

    /** Decrypted API key, '' when not set. */
    public static function apiKey()
    {
        $stored = \Option::get(self::PREFIX.'api_key', '');
        if (!$stored) {
            return '';
        }
        try {
            return trim((string)decrypt($stored));
        } catch (\Exception $e) {
            return '';
        }
    }

    public static function isConfigured()
    {
        return self::apiKey() !== '';
    }

    public static function model()
    {
        $model = (string)self::get('model', self::DEFAULT_MODEL);
        return isset(self::MODELS[$model]) ? $model : self::DEFAULT_MODEL;
    }

    public static function maxTokens()
    {
        return max(256, min(8000, (int)self::get('max_tokens', 2000)));
    }

    /** Instructions for every mailbox, then those of the mailbox. */
    public static function instructions($mailbox_id)
    {
        return trim(trim((string)self::get('instructions', ''))."\n\n".trim((string)self::get('instructions_'.(int)$mailbox_id, '')));
    }

    /** Documentation URLs, one per line. */
    public static function docsUrls()
    {
        $urls = [];
        foreach (preg_split('/\s+/', (string)self::get('docs_urls', '')) as $url) {
            if (preg_match('#^https?://#i', $url)) {
                $urls[] = $url;
            }
        }
        return array_slice(array_values(array_unique($urls)), 0, 10);
    }

    /** Estimated cost in US dollars of a usage row (cache writes 1.25 x input, cache reads 0.1 x input). */
    public static function cost($model, $input, $output, $cache_read, $cache_write)
    {
        $p = self::MODELS[$model] ?? self::MODELS[self::DEFAULT_MODEL];
        return ($input * $p[1] + $cache_write * $p[1] * 1.25 + $cache_read * $p[1] * 0.1 + $output * $p[2]) / 1000000;
    }
}
