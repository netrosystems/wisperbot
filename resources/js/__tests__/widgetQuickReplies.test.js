import { readFileSync } from 'node:fs';
import { describe, it, expect, vi } from 'vitest';

const source = readFileSync('public/widget/wisperbot-chat-widget.js', 'utf8');
const bubble = source.slice(source.indexOf('  function addBubble(message)'), source.indexOf('  function videoCardMarkup'));
const update = source.slice(source.indexOf('  function updateQuickReplies()'), source.indexOf('  function activateVideoCard'));
const format = source.slice(source.indexOf('  function formatMessageText(value)'), source.indexOf('  function updateBadge()'));

function widget(thread) {
    const body = document.createElement('div');
    const send = vi.fn();
    const handoff = { status: 'bot' };
    const escape = text => { const el = document.createElement('span'); el.textContent = text ?? ''; return el.innerHTML; };
    const runtime = new Function('body', 'thread', 'send', 'handoff', 'esc', `
        var CFG = {agent_name: 'Support', avatar_url: 'https://example.com/company.png'}, input = null, sendingText = false;
        var escAttr = esc, initial = () => 'S', scrollDown = () => {};
        ${format}
        ${bubble}
        ${update}
        return { addBubble, updateQuickReplies, formatMessageText, busy: value => {sendingText=value; updateQuickReplies();} };
    `)(body, thread, send, handoff, escape);
    thread.forEach(runtime.addBubble);
    return { ...runtime, body, send, handoff };
}

const question = { id: 2, role: 'agent', body: 'Which app?\n1. iOS\n2. Android', display_body: 'Which app?', quick_replies: [{id:'qr_1', label:'iOS'}, {id:'qr_2', label:'Android'}] };

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
