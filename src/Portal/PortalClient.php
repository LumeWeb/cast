<?php

/**
 * REMOVED — misleading workspace-token capability scaffolding.
 *
 * The workspace-token capability this class used to host derived a
 * "workspace-scoped token" from the Workspaces.Access proxy Basic Auth
 * username/password pair and treated it as a portal bearer. That conversion is
 * wrong: PORTAL_API_KEY is ALREADY the
 * workspace-scoped JWT bearer the portal injects, and Workspaces.Access
 * returns proxy Basic Auth credentials for workspace public routes — never an
 * API bearer.
 *
 * The approved runtime identity is GET /api/workspaces/resolve?resource_uuid=...
 * over the SAME PORTAL_API_KEY bearer (see WorkspaceResolveClient /
 * {@see LumeWeb\Cast\Portal\PortalFacade}). The WorkspaceToken and
 * WorkspaceTokenResult value objects are equally obsolete.
 *
 * {@see LumeWeb\Cast\Tests\Unit\Portal\PortalCredentialContractTest} pins this
 * removal: the classes no longer exist, no source references them, and no
 * production code converts a proxy username/password into a bearer.
 */

declare(strict_types=1);
