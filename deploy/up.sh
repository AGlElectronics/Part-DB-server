#!/bin/sh
# Update one Part-DB compose stack on the Docker host.
# Usage: deploy/up.sh <local|ssh> <remote-path> <compose-file> <image-tag>
set -eu

mode="${1:?mode local or ssh}"
remote_path="${2:?remote path}"
compose_file="${3:?compose file}"
image_tag="${4:?image tag}"

case "$remote_path" in
    *[!A-Za-z0-9/_.-]*)
        echo "Refusing remote path: $remote_path" >&2
        exit 1
        ;;
esac

case "$image_tag" in
    *[!A-Za-z0-9._-]*)
        echo "Refusing image tag: $image_tag" >&2
        exit 1
        ;;
esac

write_tag() {
    env_file="$1"
    if [ ! -f "$env_file" ]; then
        umask 077
        : > "$env_file"
    fi
    tmp="${env_file}.tmp"
    grep -v '^PARTDB_TAG=' "$env_file" > "$tmp" || true
    printf 'PARTDB_TAG=%s\n' "$image_tag" >> "$tmp"
    mv "$tmp" "$env_file"
}

login_ghcr() {
    if [ -n "${GHCR_PULL_TOKEN:-}" ]; then
        printf '%s' "$GHCR_PULL_TOKEN" | docker login ghcr.io -u "${GHCR_PULL_USER:?GHCR_PULL_USER is required when GHCR_PULL_TOKEN is set}" --password-stdin
    fi
}

compose_up() {
    directory="$1"
    login_ghcr
    docker compose --env-file "$directory/.env" -f "$directory/compose.yaml" pull
    docker compose --env-file "$directory/.env" -f "$directory/compose.yaml" up -d --remove-orphans
    docker compose --env-file "$directory/.env" -f "$directory/compose.yaml" ps
}

if [ "$mode" = "local" ]; then
    if ! docker info >/dev/null 2>&1; then
        echo "This runner cannot access Docker. Add its user to the docker group on the virtual machine." >&2
        exit 1
    fi
    if [ ! -d "$remote_path" ]; then
        mkdir -p "$remote_path" 2>/dev/null || sudo mkdir -p "$remote_path"
    fi
    if [ ! -w "$remote_path" ]; then
        sudo chown "$(id -u):$(id -g)" "$remote_path"
    fi
    cp "$compose_file" "$remote_path/compose.yaml"
    write_tag "$remote_path/.env"
    compose_up "$remote_path"
    exit 0
fi

if [ "$mode" != "ssh" ]; then
    echo "Unknown deploy mode: $mode" >&2
    exit 1
fi

if [ -z "${DEPLOY_HOST:-}" ] || [ -z "${DEPLOY_USER:-}" ] || [ -z "${DEPLOY_SSH_KEY:-}" ]; then
    echo "::notice title=Deploy skipped::The image was published. Add DEPLOY_HOST, DEPLOY_USER, and DEPLOY_SSH_KEY, or run this job on a self-hosted runner on the Docker virtual machine."
    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        cat >> "$GITHUB_STEP_SUMMARY" <<'EOF'
### Deploy skipped

The image is in GHCR. This job did not change the server.

The Docker engine runs in a virtual machine on Proxmox. GitHub-hosted runners cannot reach a private LAN address. Use one of these:

1. Install a GitHub Actions runner in that virtual machine, then set the repository variable `DEPLOY_RUNNER` to `self-hosted`.
2. Set the repository secrets `DEPLOY_HOST`, `DEPLOY_USER`, and `DEPLOY_SSH_KEY` if SSH to that virtual machine is reachable from GitHub.

Create `/opt/partdb/prod/.env` and `/opt/partdb/dev/.env` on the virtual machine before the first deploy if the database password is not the compose default. The production stack reuses the existing preview containers and volumes.
EOF
    fi
    exit 0
fi

umask 077
mkdir -p "$HOME/.ssh"
printf '%s' "$DEPLOY_SSH_KEY" | sed 's/\\n/\n/g' > "$HOME/.ssh/partdb_deploy"
if [ "$(tail -c 1 "$HOME/.ssh/partdb_deploy" | od -An -tu1 | tr -d ' ')" != "10" ]; then
    printf '\n' >> "$HOME/.ssh/partdb_deploy"
fi
chmod 600 "$HOME/.ssh/partdb_deploy"

ssh_base="ssh -i $HOME/.ssh/partdb_deploy -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=$HOME/.ssh/known_hosts"
target="${DEPLOY_USER}@${DEPLOY_HOST}"

$ssh_base "$target" "mkdir -p '$remote_path'"
scp -i "$HOME/.ssh/partdb_deploy" -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$HOME/.ssh/known_hosts" \
    "$compose_file" "$target:$remote_path/compose.yaml"

tag_b64="$(printf '%s' "$image_tag" | base64 -w 0)"
user_b64="$(printf '%s' "${GHCR_PULL_USER:-}" | base64 -w 0)"
token_b64="$(printf '%s' "${GHCR_PULL_TOKEN:-}" | base64 -w 0)"

$ssh_base "$target" "TAG_B64='$tag_b64' USER_B64='$user_b64' TOKEN_B64='$token_b64' REMOTE_PATH='$remote_path' bash -s" <<'EOF'
set -eu
cd "$REMOTE_PATH"
PARTDB_TAG="$(printf '%s' "$TAG_B64" | base64 -d)"
GHCR_PULL_USER="$(printf '%s' "$USER_B64" | base64 -d)"
GHCR_PULL_TOKEN="$(printf '%s' "$TOKEN_B64" | base64 -d)"
export PARTDB_TAG GHCR_PULL_USER GHCR_PULL_TOKEN
if [ -n "$GHCR_PULL_TOKEN" ]; then
    printf '%s' "$GHCR_PULL_TOKEN" | docker login ghcr.io -u "$GHCR_PULL_USER" --password-stdin
fi
touch .env
tmp=".env.tmp"
grep -v '^PARTDB_TAG=' .env > "$tmp" || true
printf 'PARTDB_TAG=%s\n' "$PARTDB_TAG" >> "$tmp"
mv "$tmp" .env
docker compose pull
docker compose up -d --remove-orphans
docker compose ps
EOF
