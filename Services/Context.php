<?php

namespace Modules\ClaudeAssistant\Services;

use App\Conversation;
use App\Thread;

/**
 * Builds what is sent to Claude for a draft:
 * - system prompt (stable for a mailbox, cached by the API): role, rules, instructions, saved replies, documentation,
 *   examples of the team's replies;
 * - user message (specific to the request): customer, previous tickets, the conversation, the agent's draft and
 *   instruction.
 */
class Context
{
    const MAX_THREAD_CHARS = 6000;
    const MAX_DOC_CHARS = 30000;
    const DOCS_CACHE_MINUTES = 1440; // 24 h

    public static function system(Conversation $conversation)
    {
        $mailbox = $conversation->mailbox;
        $company = $mailbox ? $mailbox->name : config('app.name');
        $parts = [];
        $parts[] = "You draft e-mail replies for the customer support agents of \"$company\", inside the FreeScout help desk. "
            ."An agent reviews and edits every draft before sending it.\n\n"
            ."Rules:\n"
            ."- Write only the body of the reply: no subject line and no signature (the signature is added automatically). "
            ."Start with a greeting that uses the customer's first name when you know it.\n"
            ."- Reply in the language of the customer's last message.\n"
            ."- Use only facts found in the conversation, the customer's history, the saved replies and the documentation below. "
            ."Never invent order numbers, dates, amounts, tracking links, prices or policies: when the agent has to add "
            ."something, write a short placeholder in square brackets, like [tracking number]. Facts written by the agent, "
            ."in the draft or the instruction, are correct: use them as they are, without brackets.\n"
            ."- Be clear, warm and concise. Answer every question the customer asked. No marketing tone.\n"
            ."- Write like a person: no em dashes (use a comma, a colon or a new sentence instead), no emojis.\n"
            ."- Output plain text: separate paragraphs with a blank line, and use simple \"- \" lines for lists. No Markdown headings, no bold.";
        $instructions = Settings::instructions($conversation->mailbox_id);
        if ($instructions !== '') {
            $parts[] = "Instructions from the support team (follow them):\n".$instructions;
        }
        if (Settings::flag('use_saved_replies')) {
            $saved = self::savedReplies($conversation->mailbox_id);
            if ($saved !== '') {
                $parts[] = "Saved replies of the team (reuse their facts and wording when they fit):\n".$saved;
            }
        }
        $docs = self::docs();
        if ($docs !== '') {
            $parts[] = "Documentation:\n".$docs;
        }
        if (Settings::flag('use_tone')) {
            $examples = self::toneExamples($conversation);
            if ($examples !== '') {
                $parts[] = "Recent replies written by the team, as examples of tone and length (not facts about this customer):\n".$examples;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * @param string $draft       what the agent already wrote (plain text), '' if nothing
     * @param string $instruction optional request of the agent ("refuse politely", "offer a voucher"…)
     */
    public static function user(Conversation $conversation, $draft, $instruction)
    {
        $parts = [];
        $customer = $conversation->customer;
        if ($customer) {
            $info = 'Customer: '.trim($customer->getFullName(true));
            if ($customer->company) {
                $info .= ', '.$customer->company;
            }
            $parts[] = self::mask($info.'.');
        }
        if (Settings::flag('use_history') && $customer) {
            $history = self::history($conversation);
            if ($history !== '') {
                $parts[] = "The customer's previous tickets:\n".$history;
            }
        }
        // Other modules can add context (orders, subscription, account…): return an array of text sections.
        foreach ((array)\Eventy::filter('claudeassistant.context', [], $conversation) as $section) {
            if (is_string($section) && trim($section) !== '') {
                $parts[] = self::mask(trim($section));
            }
        }
        $parts[] = "Conversation (oldest first), subject \"".$conversation->getSubject()."\":\n".self::transcript($conversation);
        if (trim($draft) !== '') {
            $parts[] = "The agent's current draft:\n".self::mask(trim($draft));
        }
        $task = trim($draft) !== ''
            ? 'Rewrite the agent\'s draft into a ready-to-send reply to the customer\'s last message, keeping its intent.'
            : 'Write the reply to the customer\'s last message.';
        if (trim($instruction) !== '') {
            $task .= "\nThe agent's instruction for this reply: ".trim($instruction);
        }
        $parts[] = $task;
        return implode("\n\n", $parts);
    }

    /** Published messages and notes of the conversation, oldest first. */
    protected static function transcript(Conversation $conversation)
    {
        $threads = $conversation->threads()
            ->where('state', Thread::STATE_PUBLISHED)
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE, Thread::TYPE_CHAT])
            ->orderBy('created_at')
            ->get();
        $lines = [];
        foreach ($threads as $thread) {
            if ($thread->type == Thread::TYPE_CUSTOMER) {
                $who = 'Customer';
            } elseif ($thread->type == Thread::TYPE_NOTE) {
                $who = 'Internal note from agent '.self::userName($thread).' (not visible to the customer)';
            } else {
                $who = 'Agent '.self::userName($thread);
            }
            $lines[] = '['.$thread->created_at->format('Y-m-d H:i').'] '.$who.":\n"
                .self::mask(self::plain($thread->body, self::MAX_THREAD_CHARS));
        }
        return implode("\n\n", $lines);
    }

    protected static function userName($thread)
    {
        $user = $thread->created_by_user;
        return $user ? $user->getFullName() : '';
    }

    protected static function history(Conversation $conversation)
    {
        $items = Conversation::where('customer_id', $conversation->customer_id)
            ->where('id', '!=', $conversation->id)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        $lines = [];
        foreach ($items as $item) {
            $lines[] = '- '.$item->created_at->format('Y-m-d').' "'.$item->getSubject().'" ('.$item->getStatusName().'): '
                .self::mask(self::plain((string)$item->preview, 200));
        }
        return implode("\n", $lines);
    }

    protected static function savedReplies($mailbox_id)
    {
        if (!\Schema::hasTable('saved_replies')) {
            return '';
        }
        $has_global = \Schema::hasColumn('saved_replies', 'global');
        $rows = \DB::table('saved_replies')
            ->where(function ($q) use ($mailbox_id, $has_global) {
                $q->where('mailbox_id', $mailbox_id);
                if ($has_global) {
                    $q->orWhere('global', 1);
                }
            })
            ->orderBy('name')
            ->limit(150)
            ->get(['name', 'text']);
        $out = [];
        foreach ($rows as $row) {
            $text = self::plain((string)$row->text, 3000);
            if ($text !== '') {
                $out[] = '## '.$row->name."\n".$text;
            }
        }
        return implode("\n\n", $out);
    }

    /** The team's last replies in this mailbox (other conversations), as tone examples. */
    protected static function toneExamples(Conversation $conversation)
    {
        $threads = Thread::join('conversations', 'conversations.id', '=', 'threads.conversation_id')
            ->where('conversations.mailbox_id', $conversation->mailbox_id)
            ->where('threads.conversation_id', '!=', $conversation->id)
            ->where('threads.type', Thread::TYPE_MESSAGE)
            ->where('threads.state', Thread::STATE_PUBLISHED)
            ->whereNotNull('threads.created_by_user_id')
            ->orderBy('threads.id', 'desc')
            ->limit(4)
            ->get(['threads.body']);
        $out = [];
        foreach ($threads as $thread) {
            $text = self::mask(self::plain($thread->body, 1500));
            if ($text !== '') {
                $out[] = "---\n".$text;
            }
        }
        return implode("\n", $out);
    }

    /** Documentation pages (llms.txt or HTML), cached 24 h. */
    public static function docs($refresh = false)
    {
        $out = [];
        foreach (Settings::docsUrls() as $url) {
            $key = 'claudeassistant_doc_'.md5($url);
            if ($refresh) {
                \Cache::forget($key);
            }
            $text = \Cache::remember($key, self::DOCS_CACHE_MINUTES, function () use ($url) {
                return self::fetch($url);
            });
            if ($text !== '') {
                $out[] = '# '.$url."\n".$text;
            }
        }
        return implode("\n\n", $out);
    }

    protected static function fetch($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'FreeScout Claude Assistant',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            \Log::warning('[ClaudeAssistant] documentation not fetched ('.$status.'): '.$url);
            return '';
        }
        return self::plain($body, self::MAX_DOC_CHARS);
    }

    /** HTML -> plain text, whitespace collapsed, truncated. */
    public static function plain($html, $max)
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', ' ', (string)$html);
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = trim(preg_replace("/\n\s*\n\s*(\n\s*)+/", "\n\n", $text));
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max).' […]';
        }
        return $text;
    }

    /**
     * "Mask personal data" option: e-mail addresses and phone numbers of the messages are replaced before anything
     * leaves the server. Applied to message contents only (not to the timestamps added here).
     */
    public static function mask($text)
    {
        if (!Settings::flag('mask_personal')) {
            return $text;
        }
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-mail]', $text);
        // phone numbers: 9 digits or more, with spaces, dots, dashes or brackets; dates are left alone
        return preg_replace_callback('/(?<![\w\/])\+?\d[\d .\-()]{7,}\d(?![\w\/])/', function ($m) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $m[0]) || preg_match_all('/\d/', $m[0]) < 9) {
                return $m[0];
            }
            return '[phone]';
        }, $text);
    }
}
