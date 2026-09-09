import { describe, expect, it } from 'vitest';
import { isNotificationForWorkspace } from '@/Utils/workspaceNotifications';

describe('workspace notification filtering', () => {
    it('accepts only the active workspace', () => {
        expect(isNotificationForWorkspace({ workspace_id: 12 }, 12)).toBe(true);
        expect(isNotificationForWorkspace({ workspace_id: '12' }, 12)).toBe(true);
        expect(isNotificationForWorkspace({ workspace_id: 13 }, 12)).toBe(false);
    });

    it('rejects unscoped and invalid payloads', () => {
        expect(isNotificationForWorkspace({}, 12)).toBe(false);
        expect(isNotificationForWorkspace({ workspace_id: null }, 12)).toBe(false);
        expect(isNotificationForWorkspace({ workspace_id: 12 }, null)).toBe(false);
    });
});
