export function isNotificationForWorkspace(notification, workspaceId) {
    const notificationWorkspaceId = Number(notification?.workspace_id);
    const activeWorkspaceId = Number(workspaceId);

    return Number.isInteger(notificationWorkspaceId)
        && notificationWorkspaceId > 0
        && Number.isInteger(activeWorkspaceId)
        && activeWorkspaceId > 0
        && notificationWorkspaceId === activeWorkspaceId;
}
