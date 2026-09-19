import { readFileSync } from 'node:fs';
import { describe, it, expect, vi } from 'vitest';

const source = readFileSync('public/widget/wisperbot-chat-widget.js', 'utf8');
const bubble = source.slice(source.indexOf('  function addBubble(message)'), source.indexOf('  function videoLinkMarkup'));
const video = source.slice(source.indexOf('  function videoLinkMarkup'), source.indexOf('  function updateQuickReplies()'));
const update = source.slice(source.indexOf('  function updateQuickReplies()'), source.indexOf('  function docIconSvg'));
const starters = source.slice(source.indexOf('  function markWelcome(row)'), source.indexOf('  function addBubble(message)'));
const format = source.slice(source.indexOf('  function formatMessageText(value)'), source.indexOf('  function updateBadge()'));

function widget(thread) {
    const body = document.createElement('div');
    const send = vi.fn();
    const handoff = { status: 'bot' };
    const escape = text => { const el = document.createElement('span'); el.textContent = text ?? ''; return el.innerHTML; };
    const runtime = new Function('body', 'thread', 'send', 'handoff', 'esc', `
        var CFG = {agent_name: 'Support', avatar_url: 'https://example.com/company.png'}, input = null, sendingText = false, prechatNeeded = false;
        var escAttr = esc, initial = () => 'S', scrollDown = () => {};
        ${format}
        ${bubble}
        ${video}
        ${update}
        return { addBubble, updateQuickReplies, formatMessageText, busy: value => {sendingText=value; updateQuickReplies();} };
    `)(body, thread, send, handoff, escape);
    thread.forEach(runtime.addBubble);
    return { ...runtime, body, send, handoff };
}

const question = { id: 2, role: 'agent', body: 'Which app?\n1. iOS\n2. Android', display_body: 'Which app?', quick_replies: [{id:'qr_1', label:'iOS'}, {id:'qr_2', label:'Android'}] };

describe('widget live chat preview', () => {
    it('first appears after five minutes and returns at most every five minutes', () => {
        vi.useFakeTimers();
        const constants = source.slice(source.indexOf('  var INVITE_DELAY_MS'), source.indexOf('  var INVITE_VISIBLE_MS'));
        const visible = source.slice(source.indexOf('  var INVITE_VISIBLE_MS'), source.indexOf('\n', source.indexOf('  var INVITE_VISIBLE_MS')) + 1);
        const schedule = source.slice(source.indexOf('  function scheduleInvite(delay)'), source.indexOf('  function switchIdentityScope()'));
        const wrap = document.createElement('div');
        const run = new Function('wrap', `var open = false, inviteTimer = null, inviteVisibleTimer = null;${constants}${visible}${schedule} scheduleInvite();`);
        run(wrap);
        const shown = () => wrap.classList.contains('wb-show-invite');

        vi.advanceTimersByTime(4 * 60 * 1000 + 59 * 1000);
        expect(shown()).toBe(false);
        vi.advanceTimersByTime(1000);
        expect(shown()).toBe(true);
        vi.advanceTimersByTime(8000);
        expect(shown()).toBe(false);
        vi.advanceTimersByTime(4 * 60 * 1000 + 59 * 1000);
        expect(shown()).toBe(false);
        vi.advanceTimersByTime(1000);
        expect(shown()).toBe(true);
        vi.useRealTimers();
    });
});

describe('widget guide videos', () => {
    const youtube = { kind: 'video', provider: 'youtube', title: 'Install guide', canonical_url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', playback_url: 'https://www.youtube.com/embed/dQw4w9WgXcQ' };

    it('shows the guide text first, then a See Tutorial link that opens YouTube in a new tab', () => {
        const { body } = widget([{ id: 3, role: 'agent', body: 'Open My eSIM, then tap Install eSIM.', resources: [youtube] }]);
        const bubble = body.querySelector('.wb-bubble');
        const caption = bubble.querySelector('.wb-caption');
        const link = bubble.querySelector('a.wb-video-link');

        expect(caption.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(link.getAttribute('href')).toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');
        expect(link.textContent).toContain('See Tutorial');
        expect(link.getAttribute('aria-label')).toBe('See tutorial: Install guide (opens YouTube in a new tab)');
        expect(bubble.querySelector('iframe, video')).toBeNull();
    });

    it('links a direct MP4 guide instead of embedding it', () => {
        const { body } = widget([{ id: 4, role: 'agent', body: 'Watch the setup.', resources: [{ kind: 'video', provider: 'direct', canonical_url: 'https://example.com/setup.mp4', playback_url: 'https://example.com/setup.mp4' }] }]);
        const link = body.querySelector('a.wb-video-link');

        expect(link.getAttribute('href')).toBe('https://example.com/setup.mp4');
        expect(body.querySelector('video, iframe')).toBeNull();
    });
});

describe('widget suggested replies', () => {
    it('keeps the approved compact shell and top-positioned human-help control', () => {
        const header = source.indexOf('<div class="wb-header">');
        const handoff = source.indexOf('<div class="wb-handoff"');
        const body = source.indexOf('<div class="wb-body">');

        expect(header).toBeGreaterThan(-1);
        expect(handoff).toBeGreaterThan(header);
        expect(body).toBeGreaterThan(handoff);
        expect(source).toContain('Talk to an agent');
        expect(source).toContain("online ? 'Team available now'");
        expect(source).toContain('.wb-header{background:#fff');
    });

    it('uses a real options menu instead of decorative dots', () => {
        expect(source).toContain('<button class="wb-more" type="button" aria-label="More options" aria-haspopup="menu" aria-expanded="false">');
        expect(source).toContain('<div class="wb-menu" role="menu" aria-label="Chat options" hidden>');
        expect(source).toContain('class="wb-menu-sound" type="button" role="menuitem"');
        expect(source).not.toContain('<span class="wb-more" aria-hidden="true">');
        expect(source).toContain('if (soundMuted) return;');
    });

    it('hides the launcher while the chat is open so the panel can use that space', () => {
        expect(source).toContain('.wb-open .wb-launcher{visibility:hidden');
        expect(source).toContain('.wb-open .wb-panel{bottom:0');
        expect(source).toContain('if (focusWasInside) launcher.focus();');
    });

    it('renders accessible buttons and sends exactly the visible text', () => {
        const view = widget([question]);
        const buttons = view.body.querySelectorAll('button');
        expect(buttons).toHaveLength(2);
        expect(buttons[0].getAttribute('aria-label')).toBe('Reply: iOS');
        expect(view.body.textContent).not.toContain('1. iOS');
        buttons[0].click();
        expect(view.send).toHaveBeenCalledWith('iOS');
        view.busy(true);
        buttons[0].click();
        expect(view.send).toHaveBeenCalledTimes(1);
    });
    it('keeps history choices inactive after a customer reply and after handoff', () => {
        const view = widget([question, {id:3, role:'visitor', body:'iOS'}]);
        expect(view.body.querySelector('button').disabled).toBe(true);
        const active = widget([question]);
        active.handoff.status = 'connected';
        active.updateQuickReplies();
        expect(active.body.querySelector('button').disabled).toBe(true);
    });
    it('does not inject HTML and retains text fallback for malformed choices', () => {
        const view = widget([{...question, quick_replies:[{label:'<img src=x onerror=alert(1)>'}]}]);
        expect(view.body.querySelector('.wb-bubble img')).toBeNull();
        expect(view.body.querySelector('button')).toBeNull();
        expect(view.body.textContent).toContain('1. iOS');
    });
    it('does not activate older messages delivered out of order', () => {
        const view = widget([question, {...question, id:1}]);
        const groups = view.body.querySelectorAll('.wb-quick-replies');
        expect(groups[0].querySelector('button').disabled).toBe(false);
        expect(groups[1].querySelector('button').disabled).toBe(true);
    });
    it('normalises markdown-escaped package links before rendering', () => {
        const input = '[https://www.telzen.net/packages/6911a730a56d9a0dcf383fe5?countryid=68fa05df73ed268e692de22e&countryname=Dominica&plan\\_type=esim](https://www.telzen.net/packages/6911a730a56d9a0dcf383fe5?countryid=68fa05df73ed268e692de22e\\&countryname=Dominica\\&plan_type=esim)';
        const view = widget([]);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = view.formatMessageText(input);
        const link = wrapper.querySelector('a');
        expect(link).not.toBeNull();
        expect(link.textContent).toBe('https://www.telzen.net/packages/6911a730a56d9a0dcf383fe5?countryid=68fa05df73ed268e692de22e&countryname=Dominica&plan_type=esim');
        expect(link.getAttribute('href')).toBe('https://www.telzen.net/packages/6911a730a56d9a0dcf383fe5?countryid=68fa05df73ed268e692de22e&countryname=Dominica&plan_type=esim');
    });
    it('uses a replying teammate photo and keeps the company mark for bot messages', () => {
        const teammate = widget([{ id: 5, role: 'agent', body: 'I can help.', agent_name: 'Ava Agent', agent_avatar_url: 'https://example.com/ava.jpg' }]);
        expect(teammate.body.querySelector('.wb-av').getAttribute('src')).toBe('https://example.com/ava.jpg');

        const bot = widget([{ id: 6, role: 'agent', body: 'Automated answer', agent_name: 'Smart Bot' }]);
        expect(bot.body.querySelector('.wb-av').getAttribute('src')).toBe('https://example.com/company.png');
    });
});

function starterWidget(labels, { prechat = false } = {}) {
    const body = document.createElement('div');
    const send = vi.fn();
    const handoff = { status: 'bot' };
    const escape = text => { const el = document.createElement('span'); el.textContent = text ?? ''; return el.innerHTML; };
    const runtime = new Function('body', 'thread', 'send', 'handoff', 'esc', 'labels', 'prechat', `
        var CFG = {agent_name: 'Support', welcome_message: 'Hi there', starter_questions: labels}, input = null, sendingText = false, prechatNeeded = prechat;
        var escAttr = esc, initial = () => 'S', scrollDown = () => {};
        ${format}
        ${starters}
        ${bubble}
        ${video}
        ${update}
        markWelcome(addBubble({ role: 'agent', body: CFG.welcome_message }));
        renderStarterQuestions();
        return {
            addBubble, updateQuickReplies, renderStarterQuestions,
            busy: value => { sendingText = value; updateQuickReplies(); },
            setPrechat: value => { prechatNeeded = value; updateQuickReplies(); },
            setLabels: value => { CFG.starter_questions = value; renderStarterQuestions(); },
        };
    `)(body, [], send, handoff, escape, labels, prechat);
    return { ...runtime, body, send, handoff };
}

const five = ['Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6'].map((label, index) => ({ id: `sq_${index}`, label }));

describe('widget starter questions', () => {
    it('lists at most five questions directly under the welcome message', () => {
        const view = starterWidget(five);
        const group = view.body.querySelector('.wb-starters');
        expect(group.getAttribute('aria-label')).toBe('Common questions');
        expect(group.previousElementSibling.hasAttribute('data-wb-welcome')).toBe(true);
        expect([...group.querySelectorAll('button')].map(b => b.textContent)).toEqual(['Q1', 'Q2', 'Q3', 'Q4', 'Q5']);
    });
    it('sends the question text and stays usable after later messages', () => {
        const view = starterWidget(five.slice(0, 2));
        view.addBubble({ id: 7, role: 'agent', body: 'Saved answer' });
        view.addBubble({ id: 8, role: 'visitor', body: 'Thanks' });
        const button = view.body.querySelector('.wb-starters button');
        expect(button.disabled).toBe(false);
        button.click();
        expect(view.send).toHaveBeenCalledWith('Q1');
        expect(view.body.firstElementChild.nextElementSibling.classList.contains('wb-starters')).toBe(true);
    });
    it('is disabled while sending, during pre-chat and while a person handles the chat', () => {
        const view = starterWidget(five.slice(0, 1), { prechat: true });
        const button = () => view.body.querySelector('.wb-starters button');
        expect(button().disabled).toBe(true);
        button().click();
        expect(view.send).not.toHaveBeenCalled();
        view.setPrechat(false);
        expect(button().disabled).toBe(false);
        view.busy(true);
        expect(button().disabled).toBe(true);
        view.busy(false);
        view.handoff.status = 'waiting';
        view.updateQuickReplies();
        expect(button().disabled).toBe(true);
    });
    it('replaces the list in place, never duplicates it, and removes it when turned off', () => {
        const view = starterWidget(five.slice(0, 2));
        view.addBubble({ id: 9, role: 'agent', body: 'Later message' });
        view.setLabels([{ id: 'sq_new', label: 'New question' }]);
        expect(view.body.querySelectorAll('.wb-starters')).toHaveLength(1);
        expect(view.body.children[1].textContent).toBe('New question');
        view.setLabels([]);
        expect(view.body.querySelector('.wb-starters')).toBeNull();
    });
    it('drops unsafe or oversized labels', () => {
        const view = starterWidget([{ id: 'a', label: '<img src=x>' }, { id: 'b', label: 'x'.repeat(81) }, { id: 'c', label: 'Fine' }]);
        expect([...view.body.querySelectorAll('.wb-starters button')].map(b => b.textContent)).toEqual(['Fine']);
        expect(view.body.querySelector('img[src="x"]')).toBeNull();
    });
});
