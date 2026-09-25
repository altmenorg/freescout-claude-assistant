<?php

namespace Modules\ClaudeAssistant\Http\Controllers;

use App\Conversation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClaudeAssistant\Services\Claude;
use Modules\ClaudeAssistant\Services\Context;
use Modules\ClaudeAssistant\Services\Settings;

class AssistantController extends Controller
{
    /** Draft (or rewrite) the reply of a conversation. Nothing is sent: the text goes into the agent's editor. */
    public function draft(Request $request)
    {
        $user = auth()->user();
        $conversation = Conversation::find((int)$request->input('conversation_id'));
        if (!$conversation || !$user->can('view', $conversation)) {
            return response()->json(['status' => 'error', 'msg' => __('Conversation not found')], 404);
        }
        $draft = mb_substr((string)$request->input('draft', ''), 0, 20000);
        $instruction = mb_substr((string)$request->input('instruction', ''), 0, 2000);

        try {
            $model = Settings::model();
            $result = Claude::complete(
                $model,
                Settings::maxTokens(),
                Context::system($conversation),
                Context::user($conversation, $draft, $instruction)
            );
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'msg' => $e->getMessage()]);
        } catch (\Exception $e) {
            \Log::error('[ClaudeAssistant] '.$e->getMessage());
            return response()->json(['status' => 'error', 'msg' => __('Claude returned an error.')]);
        }

        $usage = $result['usage'];
        $log_id = \DB::table('claude_assistant_logs')->insertGetId([
            'user_id'            => $user->id,
            'mailbox_id'         => $conversation->mailbox_id,
            'conversation_id'    => $conversation->id,
            'model'              => $model,
            'input_tokens'       => (int)($usage['input_tokens'] ?? 0),
            'output_tokens'      => (int)($usage['output_tokens'] ?? 0),
            'cache_read_tokens'  => (int)($usage['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => (int)($usage['cache_creation_input_tokens'] ?? 0),
            'created_at'         => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'html'   => self::toHtml($result['text']),
            'log_id' => $log_id,
        ]);
    }

    /** 👍 / 👎 on a draft, by the agent who generated it. */
    public function feedback(Request $request)
    {
        $rating = (int)$request->input('rating');
        if (!in_array($rating, [1, -1], true)) {
            return response()->json(['status' => 'error']);
        }
        \DB::table('claude_assistant_logs')
            ->where('id', (int)$request->input('log_id'))
            ->where('user_id', auth()->id())
            ->update(['rating' => $rating]);
        return response()->json(['status' => 'success']);
    }

    /** Settings page: re-download the documentation now instead of waiting for the 24 h cache. */
    public function refreshDocs()
    {
        $chars = mb_strlen(Context::docs(true));
        return redirect()->route('settings', ['section' => 'claudeassistant'])
            ->with('flash_success_floating', __('Documentation refreshed: :count characters loaded.', ['count' => $chars]));
    }

    /** Plain text from Claude -> editor HTML: paragraphs, "- " lists, line breaks. */
    public static function toHtml($text)
    {
        $html = [];
        foreach (preg_split("/\n\s*\n/", trim($text)) as $block) {
            $lines = preg_split("/\n/", trim($block));
            $is_list = count(array_filter($lines, function ($l) {
                return preg_match('/^\s*[-•*]\s+/', $l);
            })) === count($lines);
            if ($is_list) {
                $items = array_map(function ($l) {
                    return '<li>'.e(preg_replace('/^\s*[-•*]\s+/', '', $l)).'</li>';
                }, $lines);
                $html[] = '<ul>'.implode('', $items).'</ul>';
            } else {
                $html[] = '<p>'.implode('<br>', array_map('e', $lines)).'</p>';
            }
        }
        return implode('', $html);
    }
}
