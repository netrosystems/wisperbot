import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import { confirmDialog, ConfirmDialogHost } from '@/Components/ConfirmDialog';

describe('in-app confirm dialog', () => {
    let unmount;
    afterEach(() => unmount?.());

    // Returns the pending answer wrapped, so awaiting open() does not wait for it.
    const open = async (options) => {
        ({ unmount } = render(<ConfirmDialogHost />));
        let answer;
        await act(async () => { answer = confirmDialog(options); });
        await screen.findByRole('dialog');
        return { answer };
    };

    it('resolves true only when the confirm button is pressed', async () => {
        const { answer } = await open({ message: 'Delete this source?' });
        expect(screen.getByRole('dialog')).toHaveTextContent('Delete this source?');
        await userEvent.click(screen.getByRole('button', { name: 'Delete' }));
        await expect(answer).resolves.toBe(true);
    });

    it('starts on Cancel so Enter cannot delete by accident, and Cancel resolves false', async () => {
        const { answer } = await open({ message: 'Remove this label?', confirmLabel: 'Remove' });
        expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Remove' })).toBeInTheDocument();
        await userEvent.keyboard('{Enter}');
        await expect(answer).resolves.toBe(false);
    });

    it('resolves false on Escape', async () => {
        const { answer } = await open({ message: 'Disconnect this store?', confirmLabel: 'Disconnect' });
        await userEvent.keyboard('{Escape}');
        await expect(answer).resolves.toBe(false);
    });
});

describe('delete buttons', () => {
    it('never use the browser confirm pop-up, which Chrome can silently suppress', () => {
        const files = execSync("grep -rlE '(window\\.)?\\bconfirm\\(' resources/js/Pages || true", { encoding: 'utf8' }).split('\n').filter(Boolean);
        const offenders = files.flatMap((file) => readFileSync(file, 'utf8').split('\n')
            .filter((line) => /(^|[^\w.])(window\.)?confirm\(/.test(line) && /delet|remov|destroy|revoke|disconnect|trash|flush/i.test(line))
            .map((line) => `${file}: ${line.trim().slice(0, 80)}`));
        expect(offenders).toEqual([]);
    });
});
