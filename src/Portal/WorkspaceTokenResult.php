<?php

/**
 * REMOVED — result wrapper for the misleading workspace-token capability.
 *
 * WorkspaceTokenResult existed only to carry the Workspaces.Access-derived
 * token out of the removed PortalClient::workspaceToken(). The capability is
 * obsolete: PORTAL_API_KEY alone is the portal bearer, runtime identity is
 * resolved via Workspaces.Resolve, and no proxy Basic Auth credential is ever
 * converted into an API bearer.
 *
 * {@see LumeWeb\Cast\Tests\Unit\Portal\PortalCredentialContractTest}
 */

declare(strict_types=1);
