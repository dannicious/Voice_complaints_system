<?php

declare(strict_types=1);

/**
 * Shared "Response Timeline" comment-thread UI.
 *
 * Renders the Facebook-style comment/reply presentation used on the
 * Student, Dean, Admin, and Staff ticket/suggestion detail pages:
 * avatar on the left, sender name + role and the message inside a
 * rounded bubble, then the date and a "Reply" action underneath.
 *
 * This file is presentation only - it does not touch the database,
 * permissions, routing, or reply/save logic. Callers keep deciding
 * what data to show and whether a reply action is currently allowed;
 * this just standardizes how one entry in the thread is drawn.
 */

if (!function_exists('rt_h')) {
    function rt_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('response_timeline_styles')) {
    function response_timeline_styles(): string
    {
        // Additive only: these classes are new. Existing .timeline-* rules
        // already on the page (avatar, card, heading, name, role, time)
        // are left as-is and still apply.
        return '
.timeline-meta-row { display:flex; align-items:center; gap:14px; margin-top:5px; padding-left:2px; }
.timeline-reply-link { background:none; border:none; padding:0; margin:0; font-size:12px; font-weight:700; color:#6b7280; cursor:pointer; font-family:inherit; }
.timeline-reply-link:hover { color:#6b46c1; text-decoration:underline; }
.timeline-reply-link.is-active { color:#6b46c1; }
.timeline-avatar.is-small { width:32px; height:32px; font-size:12px; }
.timeline-replies { display:flex; flex-direction:column; gap:14px; margin:14px 0 0 21px; padding-left:21px; border-left:2px solid #eef2ff; }
.timeline-reply-box { display:none; margin-top:10px; }
.timeline-reply-box.is-open { display:block; animation: timelineReplyBoxIn .18s ease; }
@keyframes timelineReplyBoxIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
';
    }
}

if (!function_exists('response_timeline_avatar_html')) {
    function response_timeline_avatar_html(?string $photoUrl, string $name, bool $small = false): string
    {
        $initial = strtoupper(substr(trim($name), 0, 1)) ?: '?';
        $class = 'timeline-avatar' . ($small ? ' is-small' : '');
        if ($photoUrl) {
            return '<div class="' . $class . '"><img src="' . rt_h($photoUrl) . '" alt="' . rt_h($name) . '"></div>';
        }
        return '<div class="' . $class . '">' . rt_h($initial) . '</div>';
    }
}

if (!function_exists('response_timeline_entry')) {
    /**
     * Render one comment/reply bubble.
     *
     * @param array{
     *   name?: string,
     *   role_label?: string,
     *   is_current_user?: bool,
     *   photo?: ?string,
     *   time_text?: string,
     *   message_html?: string,
     *   is_reply?: bool,
     *   reply_target_id?: ?string
     * } $entry
     */
    function response_timeline_entry(array $entry): string
    {
        $name = (string)($entry['name'] ?? '');
        $roleLabel = (string)($entry['role_label'] ?? '');
        $isCurrentUser = !empty($entry['is_current_user']);
        $photo = $entry['photo'] ?? null;
        $timeText = (string)($entry['time_text'] ?? '');
        $messageHtml = (string)($entry['message_html'] ?? '');
        $isReply = !empty($entry['is_reply']);
        $replyTargetId = $entry['reply_target_id'] ?? null;

        $avatarHtml = response_timeline_avatar_html($photo, $name, $isReply);
        $displayName = $name . ($isCurrentUser ? ' (You)' : '');

        $replyLinkHtml = '';
        if ($replyTargetId) {
            $replyLinkHtml = '<button type="button" class="timeline-reply-link" data-reply-target="' . rt_h((string)$replyTargetId) . '">Reply</button>';
        }

        return '<div class="timeline-entry">'
            . $avatarHtml
            . '<div class="timeline-body">'
                . '<div class="timeline-card' . ($isCurrentUser ? ' current-user' : '') . '">'
                    . '<div class="timeline-heading">'
                        . '<div class="timeline-name">' . rt_h($displayName) . '</div>'
                        . '<div class="timeline-role">' . rt_h($roleLabel) . '</div>'
                    . '</div>'
                    . '<div class="timeline-text">' . $messageHtml . '</div>'
                . '</div>'
                . '<div class="timeline-meta-row">'
                    . '<span class="timeline-time">' . rt_h($timeText) . '</span>'
                    . $replyLinkHtml
                . '</div>'
            . '</div>'
        . '</div>';
    }
}

if (!function_exists('response_timeline_script')) {
    // Two things live here:
    //
    //  1. Reply-box open/close, wired with EVENT DELEGATION (not per-button
    //     listeners) so it keeps working for "Reply" links added later by
    //     VoiceTimeline.appendReply() below, not just the ones present at
    //     page load. Clicking "Reply" under a comment reveals that page's
    //     single reply box (the backend still only supports one reply
    //     thread per ticket - there's no per-comment reply mechanism to
    //     move to):
    //       - Points at an element with class "timeline-reply-box": the box
    //         is physically moved to sit right after the clicked response
    //         and revealed. Clicking a different "Reply" moves + reopens it
    //         there (closing wherever it was); clicking "Reply" again on the
    //         same response collapses it. Only one box is ever open at a
    //         time, and nothing is shown until a "Reply" link is clicked.
    //       - Points at anything else (e.g. a textarea that's always
    //         visible, such as the staff/status-and-remarks field): falls
    //         back to the original scroll-to-and-focus behavior, unchanged.
    //
    //  2. window.VoiceTimeline.appendReply(...) - lets a page's own AJAX
    //     reply-submit handler drop the just-saved reply straight into the
    //     visible thread (indented under the anchor response) the instant
    //     it's saved, so the sender sees it immediately without reloading
    //     the page. Purely a DOM append; it does not save anything itself.
    function response_timeline_script(): string
    {
        return <<<'HTML'
<script>
window.VoiceTimeline = (function () {
    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function avatarHtml(name, photo, small) {
        var label = String(name || '').trim();
        var initial = escapeHtml(label ? label.charAt(0).toUpperCase() : '?');
        var cls = 'timeline-avatar' + (small ? ' is-small' : '');
        if (photo) {
            return '<div class="' + cls + '"><img src="' + escapeHtml(photo) + '" alt="' + escapeHtml(label) + '"></div>';
        }
        return '<div class="' + cls + '">' + initial + '</div>';
    }

    // opts: { name, roleLabel, isCurrentUser, photo, timeText, messageHtml, isReply, replyTargetId }
    function buildEntry(opts) {
        opts = opts || {};
        var el = document.createElement('div');
        el.className = 'timeline-entry';
        var displayName = String(opts.name || '') + (opts.isCurrentUser ? ' (You)' : '');
        var replyLink = opts.replyTargetId
            ? '<button type="button" class="timeline-reply-link" data-reply-target="' + escapeHtml(opts.replyTargetId) + '">Reply</button>'
            : '';
        el.innerHTML =
            avatarHtml(opts.name, opts.photo, !!opts.isReply) +
            '<div class="timeline-body">' +
                '<div class="timeline-card' + (opts.isCurrentUser ? ' current-user' : '') + '">' +
                    '<div class="timeline-heading">' +
                        '<div class="timeline-name">' + escapeHtml(displayName) + '</div>' +
                        '<div class="timeline-role">' + escapeHtml(opts.roleLabel || '') + '</div>' +
                    '</div>' +
                    '<div class="timeline-text">' + (opts.messageHtml || '') + '</div>' +
                '</div>' +
                '<div class="timeline-meta-row">' +
                    '<span class="timeline-time">' + escapeHtml(opts.timeText || '') + '</span>' +
                    replyLink +
                '</div>' +
            '</div>';
        return el;
    }

    // Appends a newly-posted reply directly under the anchor response, in
    // the same "Responses" section rendered at page load - no reload.
    function appendReply(opts) {
        var anchor = document.querySelector('.ticket-section .timeline-entry');
        if (!anchor) return null;
        var section = anchor.closest('.ticket-section');
        var repliesWrap = section ? section.querySelector('.timeline-replies') : null;
        if (!repliesWrap) {
            repliesWrap = document.createElement('div');
            repliesWrap.className = 'timeline-replies';
            anchor.insertAdjacentElement('afterend', repliesWrap);
        }
        var entryOpts = Object.assign({}, opts, { isReply: true });
        var el = buildEntry(entryOpts);
        repliesWrap.appendChild(el);
        return el;
    }

    return { buildEntry: buildEntry, appendReply: appendReply, escapeHtml: escapeHtml };
})();

(function () {
    var activeReplyBtn = null;

    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('.timeline-reply-link') : null;
        if (!btn) return;

        var targetId = btn.getAttribute('data-reply-target');
        var target = targetId ? document.getElementById(targetId) : null;
        if (!target) return;

        if (!target.classList.contains('timeline-reply-box')) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.focus({ preventScroll: true });
            return;
        }

        var entry = btn.closest('.timeline-entry');
        if (!entry) return;

        if (activeReplyBtn) { activeReplyBtn.classList.remove('is-active'); }

        if (activeReplyBtn === btn && target.classList.contains('is-open')) {
            target.classList.remove('is-open');
            activeReplyBtn = null;
            return;
        }

        target.classList.remove('is-open');
        entry.insertAdjacentElement('afterend', target);
        btn.classList.add('is-active');
        activeReplyBtn = btn;

        window.requestAnimationFrame(function () {
            target.classList.add('is-open');
            var textarea = target.querySelector('textarea');
            if (textarea) { textarea.focus({ preventScroll: true }); }
            target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
})();
</script>

HTML;
    }
}
