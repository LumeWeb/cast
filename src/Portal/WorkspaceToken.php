<?php

/**
 * REMOVED — misleading workspace-scoped token value object.
 *
 * This class wrapped a "workspace-scoped bearer" derived from the
 * Workspaces.Access proxy Basic Auth username/password pair (username:password)
 * and was handed to API clients as a portal bearer. That derivation is wrong
 * and is removed: PORTAL_API_KEY is already the workspace-scoped JWT bearer the
 * portal injects, and the only credential any publish/domain/upload/runtime
 * client places on the wire is 'Bearer PORTAL_API_KEY'.
 *
 * See WorkspaceResolveClient for the corrected runtime identity path.
 * {@see LumeWeb\Cast\Tests\Unit\Portal\PortalCredentialContractTest}
 */

declare(strict_types=1);
