# CI/CD plan

## Goal

Build every branch in GitHub Actions, publish production images only from the protected default
branch, and let Komodo orchestrate deployments. OpenBao is the only store for deployment secrets;
GitHub Actions obtains short-lived access through OIDC.

## Image and deployment flow

1. A push to a non-default branch builds an amd64 development image.
   * Moving tag: `dev-<sanitized-branch-name>`
   * Immutable trace tag: `sha-<short-commit>`
   * Development images are never deployed to production.
2. A pull request builds a test image and runs the repository's normal test, lint, and static
   analysis workflows.
3. A merge to the protected default branch builds the amd64/arm64 production image.
   * Moving tags: `master`, `production`, and `mechanical-preview` during the compatibility period
   * Immutable trace tag: `sha-<short-commit>`
4. A release image uses `<upstream-version>-<fork-release>`, for example `2.19.2-1`.
   The fork release starts at `1` for each new upstream version.
5. After the default-branch image is published successfully, the deployment job authenticates to
   OpenBao, reads the Komodo API credentials, and asks Komodo to redeploy the production stack.

## OpenBao integration

1. Enable a JWT auth mount for GitHub Actions and configure GitHub's OIDC discovery endpoint.
2. Create a dedicated role for this repository's production deployment workflow.
3. Bind the role to:
   * repository `AGlElectronics/Part-DB-server`;
   * the default branch ref;
   * the production GitHub environment;
   * the expected OIDC audience.
4. Give the role a short token lifetime and read-only access to one deployment secret path.
5. Store these values in OpenBao KV v2:
   * Komodo URL;
   * Komodo API key;
   * Komodo API secret;
   * any registry pull credentials required by Komodo.
6. Keep only non-secret identifiers in GitHub variables: OpenBao URL, JWT role, auth mount,
   KV path, and Komodo stack name.
7. Grant `id-token: write` only to the production deployment job. Do not store a long-lived
   OpenBao token, Komodo token, SSH key, or registry token in GitHub.

## Komodo integration

1. Define the production Part-DB stack in Komodo and point it at the production compose
   configuration.
2. Configure the stack to pull `ghcr.io/aglelectronics/part-db-server:master` during the transition.
   Move to the published image digest once the stack configuration supports digest input.
3. After the image build completes, call Komodo's `DeployStack` operation for the production
   stack. Do not use a repository push webhook for this step: a push webhook can arrive before the
   image is available.
4. Wait for the Komodo operation to complete and fail the GitHub deployment if Komodo reports an
   error.
5. Run a production health check and record the deployed image digest in the job summary.

## Rollout

### Phase 1: image pipelines

* Build and publish development images on every non-default branch push.
* Build multi-architecture production images on every default-branch merge.
* Keep the existing production deployment path until Komodo and OpenBao are configured.
* Stop automatic production deployment for release-tag builds; tags publish images only.

### Phase 2: OpenBao and Komodo

* Configure the OpenBao JWT role and KV policy.
* Configure the Komodo production stack and least-privilege API identity.
* Add the non-secret GitHub variables.
* Replace the SSH deployment action with OpenBao OIDC login plus Komodo `DeployStack`.
* Test the integration against the development stack before enabling the production environment.

### Phase 3: hardening

* Pin third-party GitHub Actions to immutable commit SHAs.
* Add artifact attestations and verify the image digest before deployment.
* Add Komodo deployment status and application health checks.
* Remove the legacy SSH deployment secrets and compatibility image tags.
* Document rollback to the previous known-good image digest.

## Acceptance criteria

* Every branch push produces a traceable development image.
* Only protected default-branch commits can initiate production deployment.
* A failed test or image build cannot trigger Komodo.
* GitHub stores no long-lived deployment credential.
* Production deployment is pinned to, reports, and can roll back to a known image digest.
