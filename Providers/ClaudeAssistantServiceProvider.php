<?php

namespace Modules\ClaudeAssistant\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ClaudeAssistant\Services\Settings;

define('CLAUDEASSISTANT_MODULE', 'claudeassistant');

/**
 * Claude Assistant for FreeScout.
 *
 * A "Claude" button in the reply editor drafts the reply of the conversation (or rewrites the agent's draft) from:
 * the conversation, the customer's previous tickets, the team's saved replies, documentation pages, recent replies of
 * the team (tone) and the instructions set per mailbox. The draft goes into the editor, never sent automatically.
 * Agents rate drafts (good / needs work); the settings page shows usage, ratings and the estimated cost.
 */
class ClaudeAssistantServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'claudeassistant');
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
        $this->registerSettings();
        $this->registerAssets();
    }

    public function register()
    {
    }

    /* ------------------------------------------------------------------ settings */

    protected function registerSettings()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[CLAUDEASSISTANT_MODULE] = ['title' => 'Claude Assistant', 'icon' => 'pencil', 'order' => 660];
            return $sections;
        }, 40);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section != CLAUDEASSISTANT_MODULE) {
                return $settings;
            }
            $p = Settings::PREFIX;
            $settings[$p.'api_key'] = Settings::isConfigured() ? '********' : '';
            $settings[$p.'model'] = Settings::model();
            $settings[$p.'max_tokens'] = Settings::maxTokens();
            $settings[$p.'instructions'] = (string)Settings::get('instructions', '');
            foreach (self::mailboxes() as $mailbox) {
                $settings[$p.'instructions_'.$mailbox->id] = (string)Settings::get('instructions_'.$mailbox->id, '');
            }
            $settings[$p.'docs_urls'] = (string)Settings::get('docs_urls', '');
            foreach (Settings::FLAGS as $flag => $default) {
                $settings[$p.$flag] = Settings::flag($flag) ? 1 : 0;
            }
            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != CLAUDEASSISTANT_MODULE) {
                return $params;
            }
            return [
                'template_vars' => [
                    'models'    => Settings::MODELS,
                    'mailboxes' => self::mailboxes(),
                    'stats'     => self::stats(),
                    'flags'     => Settings::FLAGS,
                ],
                'settings' => [
                    // API key: masked in the form, kept when left as asterisks, stored encrypted
                    Settings::PREFIX.'api_key' => ['safe_password' => true, 'encrypt' => true],
                ],
            ];
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == CLAUDEASSISTANT_MODULE ? 'claudeassistant::settings' : $view;
        }, 20, 2);
    }

    protected static function mailboxes()
    {
        return \App\Mailbox::orderBy('name')->get();
    }

    /** Usage of the last 30 days, per agent: drafts, ratings, estimated cost in US dollars. */
    public static function stats()
    {
        if (!\Schema::hasTable('claude_assistant_logs')) {
            return ['rows' => [], 'total' => null];
        }
        $logs = \DB::table('claude_assistant_logs')->where('created_at', '>=', now()->subDays(30))->get();
        $users = \App\User::whereIn('id', $logs->pluck('user_id')->unique()->all())->get()->keyBy('id');
        $rows = [];
        $total = ['name' => __('Total'), 'drafts' => 0, 'good' => 0, 'bad' => 0, 'cost' => 0.0];
        foreach ($logs as $log) {
            if (!isset($rows[$log->user_id])) {
                $user = $users->get($log->user_id);
                $rows[$log->user_id] = ['name' => $user ? $user->getFullName() : '#'.$log->user_id, 'drafts' => 0, 'good' => 0, 'bad' => 0, 'cost' => 0.0];
            }
            $cost = Settings::cost($log->model, $log->input_tokens, $log->output_tokens, $log->cache_read_tokens, $log->cache_write_tokens);
            foreach ([&$rows[$log->user_id], &$total] as &$row) {
                $row['drafts']++;
                $row['good'] += $log->rating == 1 ? 1 : 0;
                $row['bad'] += $log->rating == -1 ? 1 : 0;
                $row['cost'] += $cost;
            }
            unset($row);
        }
        uasort($rows, function ($a, $b) {
            return $b['drafts'] <=> $a['drafts'];
        });
        return ['rows' => array_values($rows), 'total' => $total['drafts'] ? $total : null];
    }

    /* ------------------------------------------------------------------ editor button */

    protected function registerAssets()
    {
        // Files, not inline code: FreeScout's Content-Security-Policy blocks inline scripts. The editor button is
        // registered by module.js before FreeScout builds the reply editor.
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(CLAUDEASSISTANT_MODULE).'/css/module.css';
            return $styles;
        });
        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath(CLAUDEASSISTANT_MODULE).'/js/module.js';
            return $scripts;
        });
        // Configuration and texts for module.js, in <head> so they exist before the editor is built
        \Eventy::addAction('layout.head', function () {
            if (!auth()->check() || !Settings::isConfigured()) {
                return;
            }
            $config = [
                'draftUrl'    => route('claudeassistant.draft'),
                'feedbackUrl' => route('claudeassistant.feedback'),
                'feedback'    => Settings::flag('feedback'),
                't'           => [
                    'button'      => __('Draft with Claude'),
                    'title'       => __('Draft with Claude'),
                    'instruction' => __('Instruction (optional): "refuse politely", "offer a voucher"…'),
                    'draft'       => __('Draft the reply'),
                    'rewrite'     => __('Rewrite my draft'),
                    'working'     => __('Claude is writing…'),
                    'done'        => __('Draft by Claude. Check it before sending.'),
                    'good'        => __('Good'),
                    'bad'         => __('Needs work'),
                    'thanks'      => __('Thanks for the feedback'),
                    'undo'        => __('Undo'),
                    'close'       => __('Close'),
                    'shortcut'    => __('Ctrl+Shift+G'),
                    'error'       => __('Claude returned an error.'),
                ],
            ];
            echo '<meta name="claudeassistant-config" content="'.e(json_encode($config, JSON_UNESCAPED_UNICODE)).'">'."\n";
        });
    }
}
