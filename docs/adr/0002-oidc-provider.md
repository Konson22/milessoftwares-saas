# ADR-0002: OpenID Connect provider

- Status: Accepted (2026-10-10)
- Phase: 2 (Central Auth)

## Context

`auth.milessoftwares.com` must be an OpenID Provider for the platform's own hosts (`app.`, `admin.`, every
workspace) and for each product. The architecture requires authorization code + PKCE for every browser
client, exact redirect-URI matching (including per-tenant URIs written by provisioning), mandatory
`state` and `nonce`, short-lived access tokens, and endpoints on the auth host only.

Options considered:

1. **Laravel Passport 13 + `jeremy379/laravel-openid-connect` 3.3** (in-process, same user store).
2. **Keycloak** (separate Java service, its own user store and login UI).

## Decision

Option 1. It keeps one identity store, reuses the Fortify sign-in UI and MFA built in Phase 2, and adds no
service to operate. Keycloak remains the fallback if a conformance gap cannot be closed in-process.
Identity stays behind the Identity module, so a later swap does not reach products.

## Evaluation findings and how each is closed

| Gap in the packages as shipped                                      | Platform measure                                                                                           |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| PKCE optional for confidential clients; `plain` method accepted     | `ValidateAuthorizationRequest` requires `code_challenge` + `S256` for every client                         |
| `state` and `nonce` optional                                        | Same middleware requires both (≥16 chars); `nonce` whenever `openid` is requested                          |
| Implicit grant available via configuration                          | Never enabled; `response_type` other than `code` rejected                                                  |
| Discovery/JWKS routes registered on every host                      | Package routes disabled; discovery + JWKS mounted on the auth host only (`routes/oauth.php`)               |
| Passport routes registered on every host, incl. device + client API | `Passport::ignoreRoutes()`; only authorize/approve/deny/token mounted, on the auth host                    |
| Discovery advertises `plain` PKCE and unsupported features          | Own discovery controller lists only accepted values                                                        |
| No `kid` on tokens                                                  | `kid` = RFC 7638 thumbprint of the signing key, in token header and JWKS                                   |
| Issuer derived from the request host                                | Issuer fixed to the auth host URL at boot                                                                  |
| Redirect URIs stored only on `oauth_clients`                        | `OAuthClient` merges exact rows from `application_client_redirect_uris` (per-tenant, provisioning-written) |
| Integer user ids in Passport migrations                             | Published migrations changed to UUID foreign keys                                                          |

## Known limitations (accepted for now)

- id_token lifetime is fixed by the package at 1 hour; `auth_time`/`amr` claims are not emitted.
- No RP-initiated logout (`end_session_endpoint`) yet; not advertised in discovery.
- Not yet run against the OpenID Foundation conformance suite; the Phase 2 tests cover the flow, PKCE,
  redirect, `state`/`nonce` and host-isolation cases. A conformance run is a Phase 10 checklist item.

## Consequences

- The package is a single-maintainer fork; upgrades are pinned and re-verified by
  `tests/Feature/Identity/OpenIdProviderTest.php`.
- Signing keys live in `storage/oauth-*.key` locally (gitignored) and in `PASSPORT_PRIVATE_KEY` /
  `PASSPORT_PUBLIC_KEY` in deployed environments.
