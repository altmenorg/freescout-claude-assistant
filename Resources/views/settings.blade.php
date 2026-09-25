{{-- block form only: Laravel 5.5 mis-compiles a view mixing @php(...) and @php … @endphp --}}
@php
    $p = \Modules\ClaudeAssistant\Services\Settings::PREFIX;
@endphp
<form class="form-horizontal margin-top margin-bottom" method="POST" action="" autocomplete="off">
    {{ csrf_field() }}

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <p class="text-help">
                {{ __('Adds a "Claude" button to the reply editor: Claude drafts the reply, or rewrites the agent\'s draft, from the conversation, the customer\'s previous tickets, your saved replies, your documentation and your team\'s tone. The agent always reviews the draft before sending it.') }}
                <a href="https://github.com/altmenorg/freescout-claude-assistant#readme" target="_blank" rel="noopener">{{ __('Setup guide') }}</a>
            </p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Connection') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API key') }}</label>
        <div class="col-sm-6">
            <input type="password" name="settings[{{ $p }}api_key]" value="{{ $settings[$p.'api_key'] }}" class="form-control input-sized-lg" autocomplete="new-password" />
            <p class="form-help">{{ __('Anthropic API key (console.anthropic.com › API keys). Billed by Anthropic per use; a Claude.ai subscription does not include API usage. Stored encrypted.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Model') }}</label>
        <div class="col-sm-6">
            <select name="settings[{{ $p }}model]" class="form-control input-sized-lg">
                @foreach ($models as $id => $m)
                    <option value="{{ $id }}" @if ($settings[$p.'model'] == $id) selected @endif>{{ $m[0] }} ({{ __('$:in / $:out per million tokens in / out', ['in' => $m[1], 'out' => $m[2]]) }})</option>
                @endforeach
            </select>
            <p class="form-help">{{ __('Claude Opus 5 writes the best replies. Claude Sonnet 5 and Claude Haiku 4.5 cost less. A typical draft costs a few cents with Opus 5.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Maximum length') }}</label>
        <div class="col-sm-6">
            <div class="input-group input-sized">
                <input type="number" min="256" max="8000" step="1" name="settings[{{ $p }}max_tokens]" value="{{ $settings[$p.'max_tokens'] }}" class="form-control" />
                <span class="input-group-addon">{{ __('tokens') }}</span>
            </div>
            <p class="form-help">{{ __('Upper limit of a draft (about 0.75 word per token). 2000 is plenty for an e-mail.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Instructions') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('All mailboxes') }}</label>
        <div class="col-sm-6">
            <textarea name="settings[{{ $p }}instructions]" rows="5" class="form-control" placeholder="{{ __('Who you are, what you sell, your policies (returns, refunds, delivery times), the tone to use, what never to promise…') }}">{{ $settings[$p.'instructions'] }}</textarea>
            <p class="form-help">{{ __('Given to Claude with every request.') }}</p>
        </div>
    </div>
    @foreach ($mailboxes as $mailbox)
        <div class="form-group">
            <label class="col-sm-2 control-label">{{ $mailbox->name }}</label>
            <div class="col-sm-6">
                <textarea name="settings[{{ $p }}instructions_{{ $mailbox->id }}]" rows="3" class="form-control" placeholder="{{ __('Optional. Added for this mailbox only.') }}">{{ $settings[$p.'instructions_'.$mailbox->id] }}</textarea>
            </div>
        </div>
    @endforeach

    <h3 class="subheader">{{ __('Knowledge') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Documentation') }}</label>
        <div class="col-sm-6">
            <textarea name="settings[{{ $p }}docs_urls]" rows="3" class="form-control" placeholder="https://example.com/llms.txt">{{ $settings[$p.'docs_urls'] }}</textarea>
            <p class="form-help">{{ __('Optional. Up to 10 addresses, one per line: FAQ, help pages, an llms.txt file… Their text is given to Claude and refreshed every 24 hours.') }}</p>
            {{-- same form, other address: nothing is saved, the pages are downloaded again --}}
            <button type="submit" formaction="{{ route('claudeassistant.refresh_docs') }}" class="btn btn-default btn-sm">{{ __('Refresh the documentation now') }}</button>
        </div>
    </div>

    @php
        $groups = [
            __('Also give Claude') => [
                'use_saved_replies' => __('Use the saved replies of the mailbox as knowledge'),
                'use_history'       => __('Give the customer\'s previous tickets (subjects and summaries)'),
                'use_tone'          => __('Give a few recent replies of the team as examples of tone'),
            ],
        ];
        $privacy = [
            'mask_personal' => __('Mask e-mail addresses and phone numbers before sending to Anthropic'),
            'feedback'      => __('Ask agents to rate drafts (good / needs work)'),
        ];
    @endphp
    @foreach ($groups as $group_label => $flag_labels)
        <div class="form-group">
            <label class="col-sm-2 control-label">{{ $group_label }}</label>
            <div class="col-sm-6">
                @foreach ($flag_labels as $flag => $label)
                    <div class="checkbox">
                        <label>
                            {{-- hidden 0 first: an unchecked box is not sent at all --}}
                            <input type="hidden" name="settings[{{ $p }}{{ $flag }}]" value="0">
                            <input type="checkbox" name="settings[{{ $p }}{{ $flag }}]" value="1" @if ($settings[$p.$flag]) checked @endif> {{ $label }}
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <h3 class="subheader">{{ __('Privacy and feedback') }}</h3>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            @foreach ($privacy as $flag => $label)
                <div class="checkbox">
                    <label>
                        <input type="hidden" name="settings[{{ $p }}{{ $flag }}]" value="0">
                        <input type="checkbox" name="settings[{{ $p }}{{ $flag }}]" value="1" @if ($settings[$p.$flag]) checked @endif> {{ $label }}
                    </label>
                </div>
            @endforeach
        </div>
    </div>

    {{-- one button for the whole page, clearly apart from the last section --}}
    <div class="form-group" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e3e8ef;">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save settings') }}</button>
        </div>
    </div>
</form>

<h3 class="subheader">{{ __('Last 30 days') }}</h3>
<div class="row">
    <div class="col-sm-8 col-sm-offset-2">
        @if ($stats['total'])
            <table class="table table-condensed">
                <thead>
                    <tr>
                        <th>{{ __('Agent') }}</th>
                        <th class="text-right">{{ __('Drafts') }}</th>
                        <th class="text-right">👍 {{ __('Good') }}</th>
                        <th class="text-right">👎 {{ __('Needs work') }}</th>
                        <th class="text-right">{{ __('Estimated cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_merge($stats['rows'], [$stats['total']]) as $i => $row)
                        <tr @if ($i === count($stats['rows'])) style="font-weight: 600" @endif>
                            <td>{{ $row['name'] }}</td>
                            <td class="text-right">{{ $row['drafts'] }}</td>
                            <td class="text-right">{{ $row['good'] }}</td>
                            <td class="text-right">{{ $row['bad'] }}</td>
                            <td class="text-right">${{ number_format($row['cost'], 3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="form-help">{{ __('Estimate from the token counts and Anthropic\'s list prices; your Anthropic console shows the exact amount.') }}</p>
        @else
            <p class="text-help">{{ __('No draft yet.') }}</p>
        @endif
    </div>
</div>
