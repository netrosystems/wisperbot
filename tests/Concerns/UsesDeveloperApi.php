<?php

namespace Tests\Concerns;

/**
 * The Developer API (/api/v1) requires the Developer Tools add-on. Test
 * classes that exercise it get the add-on on every workspace they create;
 * the add-on gate itself is covered by DeveloperToolsAddonTest.
 */
trait UsesDeveloperApi
{
    protected function createWorkspaceContext(array $clientAttrs = [], array $userAttrs = [], array $workspaceAttrs = []): array
    {
        $context = parent::createWorkspaceContext($clientAttrs, $userAttrs, $workspaceAttrs);
        $this->enableDeveloperTools($context['client']->id, $context['user']->id);

        return $context;
    }
}
