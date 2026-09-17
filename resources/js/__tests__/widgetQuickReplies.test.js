import { readFileSync } from 'node:fs';
import { describe, it, expect, vi } from 'vitest';

const source = readFileSync('public/widget/wisperbot-chat-widget.js', 'utf8');
const cache = source.slice(source.indexOf('  function messageOrder(message)'), source.indexOf('  function identityPayload(extra)'));
const bubble = source.slice(source.indexOf('  function addBubble(message)'), source.indexOf('  function videoCardMarkup'));
const update = source.slice(source.indexOf('  function updateQuickReplies()'), source.indexOf('  function activateVideoCard'));
const format = source.slice(source.indexOf('  function formatMessageText(value)'), source.indexOf('  function updateBadge()'));

function widget(thread) {
    const body = document.createElement('div');
    const send = vi.fn();
    const handoff = { status: 'bot' };
    const escape = text => { const el = document.createElement('span'); el.textContent = text ?? ''; return el.innerHTML; };
    const runtime = new Function('body', 'thread', 'send', 'handoff', 'esc', `
        var CFG = {agent_name: 'Support'}, input = null, sendingText = false;
        var escAttr = esc, initial = () => 'S', scrollDown = () => {};
        ${format}
        ${bubble}
        ${update}
        return { addBubble, updateQuickReplies, formatMessageText, busy: value => {sendingText=value; updateQuickReplies();} };
    `)(body, thread, send, handoff, escape);
    thread.forEach(runtime.addBubble);
    return { ...runtime, body, send, handoff };
}

function reconcileWidget(cached, server, conversationId = 7) {
    const body = document.createElement('div');
    const escape = text => { const el = document.createElement('span'); el.textContent = text ?? ''; return el.innerHTML; };
    return new Function('body', 'cached', 'server', 'conversationId', 'esc', `
        var thread = cached.slice(), rendered = {}, lastId = 0;
        var LS_THREAD = 'thread', LS_CONVERSATION = 'conversation';
        var CFG = {agent_name: 'Support'}, input = null, sendingText = false;
        var safeGet = () => '', safeSet = () => {}, escAttr = esc, initial = () => 'S';
        var scrollDown = () => {}, send = () => {}, handoff = {status: 'bot'};
        ${format}
        ${bubble}
        ${update}
        ${cache}
        thread.forEach(addBubble);
        replaceThreadFromSession(server, conversationId);
        return {body, thread, lastId};
    `)(body, cached, server, conversationId, escape);
}

const question = { id: 2, role: 'agent', body: 'Which app?\n1. iOS\n2. Android', display_body: 'Which app?', quick_replies: [{id:'qr_1', label:'iOS'}, {id:'qr_2', label:'Android'}] };

describe('widget suggested replies', () => {
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
        expect(view.body.querySelector('img')).toBeNull();
        expect(view.body.querySelector('button')).toBeNull();
        expect(view.body.textContent).toContain('1. iOS');
    });
    it('does not activate older messages delivered out of order', () => {
        const view = widget([question, {...question, id:1}]);
        const newest = view.body.querySelector('[data-wb-message-id="2"] .wb-quick-replies');
        const older = view.body.querySelector('[data-wb-message-id="1"] .wb-quick-replies');
        expect(newest.querySelector('button').disabled).toBe(false);
        expect(older.querySelector('button').disabled).toBe(true);
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
    it('renders activity as escaped centered text without a message bubble or choices', () => {
        const activity = {id: 3, role: 'agent', kind: 'activity', body: '<img src=x> joined the chat', activity: {actor_name: '<img src=x>'}};
        const view = widget([question, activity]);
        const row = view.body.querySelector('.wb-activity');
        expect(row).not.toBeNull();
        expect(row.textContent).toBe('<img src=x> joined the chat');
        expect(row.querySelector('img')).toBeNull();
        expect(row.querySelector('.wb-activity-actor').textContent).toBe('<img src=x>');
        expect(row.querySelector('.wb-bubble')).toBeNull();
        expect(row.querySelector('button')).toBeNull();
        expect(view.body.querySelector('.wb-quick-replies button').disabled).toBe(false);
    });
    it('inserts a late activity at its canonical position instead of appending it', () => {
        const customerMessage = {id: 11, role: 'visitor', body: 'hello'};
        const resolved = {id: 10, role: 'agent', kind: 'activity', body: 'Resolved by Rahim'};
        const view = widget([customerMessage, resolved]);
        const rows = [...view.body.querySelectorAll('[data-wb-message-id]')];

        expect(rows.map(row => row.getAttribute('data-wb-message-id'))).toEqual(['10', '11']);
        expect(rows.map(row => row.textContent)).toEqual(['Resolved by Rahim', 'hello⋯']);
    });
    it('drops stale cached messages when the authoritative session belongs to the current conversation', () => {
        const view = reconcileWidget(
            [{id: 999, role: 'visitor', body: 'test again'}],
            [{id: 12, role: 'visitor', body: 'current message'}],
        );

        expect(view.thread.map(message => message.body)).toEqual(['current message']);
        expect(view.body).toHaveTextContent('current message');
        expect(view.body).not.toHaveTextContent('test again');
        expect(view.lastId).toBe(12);
    });
});
