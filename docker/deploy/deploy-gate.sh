#!/usr/bin/env bash
# Deploy gate. Run on this host by the self-hosted runner's deploy job:
#   IMAGE=... IMAGE_TAG=... REGISTRY_USER=... REGISTRY_TOKEN=... bash docker/deploy/deploy-gate.sh deploy <env>
# Commands: deploy <env> | version <env>
set -euo pipefail

tmp=""
cid=""
ROOT="${SAVEME_ROOT:-/srv/saveme}"
REGISTRY="${REGISTRY:-ghcr.io}"
ALLOWED_ENVS=(staging production)
COMMAND_NAME=saveme

# Keyrings are JSON maps {kid: base64 32-byte key}; each pairs with an active-key variable naming the kid.
KEYRINGS=(
    IDENTITY_KEYS:IDENTITY_ACTIVE_KEY
    ADMIN_KEYS:ADMIN_ACTIVE_KEY
    BILLING_KEYS:BILLING_ACTIVE_KEY
    NOTIFICATIONS_KEYS:NOTIFICATIONS_ACTIVE_KEY
    BILLING_PLAY_ACCOUNT_KEYS:BILLING_PLAY_ACCOUNT_ACTIVE_KEY
)
# Standalone base64 32-byte keys.
SINGLE_KEYS=(IDENTITY_LOOKUP_KEY ADMIN_LOOKUP_KEY ADMIN_CODE_KEY NOTIFICATIONS_LOOKUP_KEY BILLING_PLAY_LOOKUP_KEY)

log() { printf '[gate] %s\n' "$*"; }
die() { printf '[gate] ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<USAGE
Usage: deploy-gate.sh deploy <env> | version <env>
Envs: ${ALLOWED_ENVS[*]}
Env vars: IMAGE, IMAGE_TAG, REGISTRY_USER, REGISTRY_TOKEN (deploy); SAVEME_ROOT, SKIP_PULL (optional)
Host needs: docker + compose plugin, runner user in the docker group, write access to $ROOT.
USAGE
}

check_env() {
    local candidate
    for candidate in "${ALLOWED_ENVS[@]}"; do
        [[ "$1" == "$candidate" ]] && return 0
    done
    die "env '$1' not allowed (${ALLOWED_ENVS[*]})"
}

ensure_dir() {
    mkdir -p "$1" 2>/dev/null || {
        sudo -n mkdir -p "$1" && sudo -n chown "$(id -u):$(id -g)" "$1"
    } || die "cannot create $1; run: sudo mkdir -p $1 && sudo chown $(id -un): $1"
}

env_has() { grep -qE "^$2=" "$1"; }

env_get() { grep -E "^$2=" "$1" | tail -n1 | cut -d= -f2-; }

env_set() {
    local file="$1" key="$2" value="$3"
    if env_has "$file" "$key"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

env_empty() { env_has "$1" "$2" && [[ -z "$(env_get "$1" "$2")" ]]; }

# Rename keys flagged by "# renamed-from: OLD" and append keys missing from the target; never overwrite values.
reconcile_env() {
    local example="$1" target="$2" line old="" key value marker_written=0
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" =~ ^#[[:space:]]*renamed-from:[[:space:]]*([A-Za-z_][A-Za-z0-9_]*) ]]; then
            old="${BASH_REMATCH[1]}"
            continue
        fi
        if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
            key="${BASH_REMATCH[1]}"
            value="${BASH_REMATCH[2]}"
            if ! env_has "$target" "$key"; then
                if [[ -n "$old" ]] && env_has "$target" "$old"; then
                    sed -i "s|^${old}=|${key}=|" "$target"
                    log "renamed $old -> $key in $target"
                else
                    if [[ "$marker_written" -eq 0 ]]; then
                        printf '\n# added by deploy %s %s\n' "${IMAGE_TAG:-unknown}" "$(date -u +%FT%TZ)" >> "$target"
                        marker_written=1
                    fi
                    printf '%s=%s\n' "$key" "$value" >> "$target"
                    log "added $key to $target"
                fi
            fi
        fi
        old=""
    done < "$example"
}

random_secret() { openssl rand -base64 24 | tr -d '/+=\n'; }

random_key() { openssl rand -base64 32 | tr -d '\n'; }

fill_secrets() {
    local file="$1"; shift
    local key
    for key in "$@"; do
        if env_empty "$file" "$key"; then
            if [[ "$key" == "APP_KEY" ]]; then
                env_set "$file" "$key" "base64:$(random_key)"
            else
                env_set "$file" "$key" "$(random_secret)"
            fi
            log "generated $key in $file"
        fi
    done
}

# Generate empty keyrings and standalone keys once; existing values are never rotated.
fill_keys() {
    local file="$1" pair ring active key
    for pair in "${KEYRINGS[@]}"; do
        ring="${pair%%:*}"
        active="${pair##*:}"
        if env_empty "$file" "$ring"; then
            env_set "$file" "$ring" "'{\"k1\":\"$(random_key)\"}'"
            env_set "$file" "$active" "k1"
            log "generated $ring in $file"
        fi
    done
    for key in "${SINGLE_KEYS[@]}"; do
        if env_empty "$file" "$key"; then
            env_set "$file" "$key" "$(random_key)"
            log "generated $key in $file"
        fi
    done
}

# Seed .env from example on first deploy, then reconcile names/additions on every deploy.
sync_env_file() {
    local example="$1" target="$2"
    [[ -f "$example" ]] || die "missing $example"
    if [[ ! -f "$target" ]]; then
        cp "$example" "$target"
        chmod 600 "$target"
        log "created $target from $(basename "$example")"
    else
        reconcile_env "$example" "$target"
    fi
}

export_command() {
    local bin="$ROOT/bin/$COMMAND_NAME" marker="# saveme chores"
    if ln -sf "$bin" "/usr/local/bin/$COMMAND_NAME" 2>/dev/null || sudo -n ln -sf "$bin" "/usr/local/bin/$COMMAND_NAME" 2>/dev/null; then
        return 0
    fi
    mkdir -p "$HOME/.local/bin"
    ln -sf "$bin" "$HOME/.local/bin/$COMMAND_NAME"
    if ! grep -qF "$marker" "$HOME/.bashrc" 2>/dev/null; then
        printf '\n%s\nexport PATH="$PATH:%s"\n' "$marker" "$ROOT/bin" >> "$HOME/.bashrc"
    fi
}

cmd_deploy() {
    local env="$1"
    : "${IMAGE:?IMAGE required}" "${IMAGE_TAG:?IMAGE_TAG required}"
    local ref="$IMAGE:$IMAGE_TAG"

    ensure_dir "$ROOT"
    mkdir -p "$ROOT/bin" "$ROOT/infra" "$ROOT/$env"

    if [[ "${SKIP_PULL:-0}" != "1" ]]; then
        if [[ -n "${REGISTRY_TOKEN:-}" ]]; then
            printf '%s' "$REGISTRY_TOKEN" | docker login "$REGISTRY" -u "${REGISTRY_USER:?REGISTRY_USER required}" --password-stdin
        fi
        docker pull "$ref"
    fi

    tmp="$(mktemp -d)"
    cid="$(docker create "$ref")"
    trap 'docker rm -f "${cid:-}" >/dev/null 2>&1 || true; rm -rf "${tmp:-}"' EXIT
    docker cp "$cid:/opt/deploy/." "$tmp"

    [[ -d "$tmp/$env" ]] || die "image has no deploy setup for '$env'"

    cp -a "$tmp/$env/." "$ROOT/$env/"
    cp -a "$tmp/infra/." "$ROOT/infra/"
    install -m 755 "$tmp/bin/$COMMAND_NAME" "$ROOT/bin/$COMMAND_NAME"
    install -m 755 "$tmp/deploy-gate.sh" "$ROOT/bin/deploy-gate.sh"

    sync_env_file "$ROOT/$env/$env.env.example" "$ROOT/$env/.env"
    sync_env_file "$ROOT/infra/infra.env.example" "$ROOT/infra/.env"
    fill_secrets "$ROOT/$env/.env" APP_KEY DB_PASSWORD
    fill_keys "$ROOT/$env/.env"
    fill_secrets "$ROOT/infra/.env" POSTGRES_PASSWORD
    env_set "$ROOT/$env/.env" IMAGE "$IMAGE"

    export_command

    "$ROOT/bin/$COMMAND_NAME" deploy "$env" "$IMAGE_TAG"
}

cmd_version() {
    local env="$1" file="$ROOT/$1/.env" cid
    [[ -f "$file" ]] || die "$file missing; nothing deployed for $env"
    echo "env:       $env"
    echo "image:     $(env_get "$file" IMAGE):$(env_get "$file" IMAGE_TAG)"
    echo "url:       $(env_get "$file" APP_URL)"
    cid="$(docker ps -q --filter "name=saveme-$env-app" | head -n1)"
    if [[ -n "$cid" ]]; then
        echo "running:   $(docker inspect -f '{{.Config.Image}}' "$cid")"
        echo "revision:  $(docker inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$cid")"
        echo "health:    $(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}n/a{{end}}' "$cid")"
    else
        echo "running:   none"
    fi
}

main() {
    local cmd="${1:-}" env="${2:-}"
    case "$cmd" in
        deploy|version)
            [[ -n "$env" ]] || { usage; exit 1; }
            check_env "$env"
            "cmd_$cmd" "$env"
            ;;
        *) usage; exit 1 ;;
    esac
}

main "$@"
