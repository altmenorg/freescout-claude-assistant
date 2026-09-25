/* Claude Assistant: "Claude" button in the reply editor.
   Loaded with the page scripts ("javascripts" filter), so that the button is registered before FreeScout builds the
   editor (initReplyForm). Configuration and texts come from <meta name="claudeassistant-config"> (settings, language).
   The draft goes into the editor: nothing is ever sent without the agent. */
(function () {
    var meta = document.querySelector('meta[name="claudeassistant-config"]');
    if (!meta || typeof fs_conv_editor_buttons === 'undefined' || typeof fsAddFilter === 'undefined') {
        return;
    }
    var cfg;
    try { cfg = JSON.parse(meta.getAttribute('content')); } catch (e) { return; }
    var t = cfg.t;

    var conversationId = function () {
        return (typeof getGlobalAttr === 'function' && getGlobalAttr('conversation_id')) || '';
    };
    var editor = function () { return $('#body'); };
    var plain = function (html) {
        return $.trim($('<div>').html(String(html || '').replace(/<(br|\/p|\/div|\/li)[^>]*>/gi, '\n')).text());
    };

    // Summernote button (FreeScout's editor): only in a conversation, not on the new ticket form (nothing to answer)
    fs_conv_editor_buttons.claudeAssistant = function () {
        return $.summernote.ui.button({
            contents: '<span class="ca-btn"><i class="ca-icon"></i><span class="ca-btn-label">Claude</span></span>',
            tooltip: t.title + ' (' + t.shortcut + ')',
            container: 'body',
            click: function () { togglePanel(); }
        }).render();
    };
    fsAddFilter('conversation.editor_toolbar', function (toolbar) {
        if (!conversationId()) {
            return toolbar;
        }
        var out = [];
        for (var i = 0; i < toolbar.length; i++) {
            if (toolbar[i][0] === 'actions') { out.push(['claude', ['claudeAssistant']]); }
            out.push(toolbar[i]);
        }
        if (out.length === toolbar.length) { out.push(['claude', ['claudeAssistant']]); }
        return out;
    });

    var panel = null, bar = null, busy = false, lastLogId = null, previousHtml = null;

    function container() {
        return editor().next('.note-editor');
    }

    // Refresh interface: its own bar under the text (formatting, attachment, saved replies) stays visible on the
    // phone and when the formatting toolbar is folded, so the button goes there, and the panel just above it.
    function inRefreshTools() {
        return container().hasClass('ca-in-tools');
    }
    function placeInRefreshTools() {
        var tools = container().find('.rf-ed-tools').first();
        if (!tools.length || tools.find('.ca-tools-btn').length) {
            return;
        }
        tools.append($('<button type="button" class="rf-ed-ic ca-tools-btn"></button>')
            .attr('title', t.title + ' (' + t.shortcut + ')')
            .html('<span class="ca-btn"><i class="ca-icon"></i><span class="ca-btn-label">Claude</span></span>')
            .on('click', function () { togglePanel(); }));
        container().addClass('ca-in-tools');
    }
    function attach(el) {
        if (inRefreshTools()) {
            container().find('.note-statusbar').first().before(el);
        } else {
            container().find('.note-toolbar').first().after(el);
        }
    }
    if (conversationId()) {
        // Refresh builds its bar when the reply editor opens
        setInterval(placeInRefreshTools, 1000);
    }

    function togglePanel(forceOpen) {
        if (panel && panel.is(':visible') && !forceOpen) {
            panel.hide();
            return;
        }
        if (!panel) {
            panel = $('<div class="ca-panel"></div>');
            panel.append($('<textarea class="form-control ca-instruction" rows="2"></textarea>').attr('placeholder', t.instruction));
            var actions = $('<div class="ca-actions"></div>');
            actions.append($('<button type="button" class="btn btn-primary ca-go"></button>').text(t.draft));
            actions.append($('<button type="button" class="btn btn-link ca-close"></button>').text(t.close));
            actions.append($('<span class="ca-hint"></span>').text(t.shortcut));
            panel.append(actions);
            panel.on('click', '.ca-go', generate);
            panel.on('click', '.ca-close', function () { panel.hide(); });
            panel.on('keydown', '.ca-instruction', function (e) {
                if (e.keyCode === 13 && (e.ctrlKey || e.metaKey)) { e.preventDefault(); generate(); }
                if (e.keyCode === 27) { panel.hide(); }
            });
        }
        attach(panel);
        // with a draft in the editor, the request rewrites it
        panel.find('.ca-go').text(plain(editor().summernote('code')) ? t.rewrite : t.draft);
        panel.show();
        panel.find('.ca-instruction').focus();
    }

    function generate() {
        if (busy) { return; }
        busy = true;
        var draft = plain(editor().summernote('code'));
        var btn = panel.find('.ca-go').prop('disabled', true);
        var label = btn.text();
        btn.html('<span class="ca-spinner"></span> ').append(document.createTextNode(t.working));
        $.ajax({
            url: cfg.draftUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                conversation_id: conversationId(),
                draft: draft,
                instruction: panel.find('.ca-instruction').val()
            }
        }).done(function (r) {
            if (!r || r.status !== 'success') {
                showError((r && r.msg) || t.error);
                return;
            }
            previousHtml = editor().summernote('code');
            editor().summernote('code', r.html);
            editor().summernote('focus');
            lastLogId = r.log_id;
            panel.hide();
            panel.find('.ca-instruction').val('');
            showBar();
        }).fail(function (xhr) {
            showError((xhr.responseJSON && xhr.responseJSON.msg) || t.error);
        }).always(function () {
            busy = false;
            btn.prop('disabled', false).text(label);
        });
    }

    function showError(msg) {
        if (typeof showFloatingAlert === 'function') {
            showFloatingAlert('error', msg);
        } else {
            alert(msg);
        }
    }

    // Under the toolbar after a draft: rating and undo
    function showBar() {
        if (bar) { bar.remove(); }
        bar = $('<div class="ca-bar"></div>');
        bar.append($('<span class="ca-bar-text"></span>').append('<i class="ca-icon"></i> ', document.createTextNode(t.done)));
        if (cfg.feedback) {
            bar.append($('<button type="button" class="ca-rate" data-rating="1"></button>').text('👍 ' + t.good));
            bar.append($('<button type="button" class="ca-rate" data-rating="-1"></button>').text('👎 ' + t.bad));
        }
        bar.append($('<button type="button" class="ca-undo"></button>').text(t.undo));
        bar.append($('<button type="button" class="ca-bar-x">×</button>').attr('title', t.close));
        bar.on('click', '.ca-rate', function () {
            var rating = $(this).attr('data-rating');
            $.post(cfg.feedbackUrl, { _token: $('meta[name="csrf-token"]').attr('content'), log_id: lastLogId, rating: rating });
            bar.find('.ca-rate').remove();
            bar.find('.ca-bar-text').after($('<span class="ca-thanks"></span>').text(t.thanks));
        });
        bar.on('click', '.ca-undo', function () {
            if (previousHtml !== null) { editor().summernote('code', previousHtml); }
            bar.remove();
        });
        bar.on('click', '.ca-bar-x', function () { bar.remove(); });
        attach(bar);
    }

    // Ctrl+Shift+G: opens the panel of the reply being written
    $(document).on('keydown', function (e) {
        if (e.keyCode === 71 && e.shiftKey && (e.ctrlKey || e.metaKey) && conversationId() && container().is(':visible')) {
            e.preventDefault();
            togglePanel(true);
        }
    });
})();
