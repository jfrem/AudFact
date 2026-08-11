#!/usr/bin/env bash
set -euo pipefail

# Disable path conversion in Git Bash (MSYS) when calling Windows native executables (like gh.exe)
# to prevent mangling POSIX absolute paths into Windows paths.
export MSYS_NO_PATHCONV=1
if [[ "$-" == *x* ]]; then
  echo "error: do not run this script with xtrace/bash -x; it would risk exposing secrets." >&2
  exit 2
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"
EXAMPLE_FILE="$ROOT_DIR/.env.example"
GITHUB_ENVIRONMENT="production"
REPO="${GH_REPO:-}"
MODE="dry-run"
SYNC_SECRETS=1
SYNC_VARIABLES=1
GENERATE_TARGET=""
GENERATE_DRY_RUN=0

SECRET_KEYS=(
  DB_USER
  DB_PASS
  DB2_USER
  DB2_PASS
  GEMINI_API_KEY
  GOOGLE_DRIVE_PRIVATE_KEY
  MCP_WEBHOOK_SECRET
  REDIS_PASSWORD
)

REQUIRED_SECRET_KEYS=(
  DB_USER
  DB_PASS
  DB2_USER
  DB2_PASS
  GEMINI_API_KEY
  MCP_WEBHOOK_SECRET
)

REQUIRED_VARIABLE_KEYS=(
  DB_HOST
  DB_PORT
  DB_NAME
  DB2_HOST
  DB2_PORT
  DB2_NAME
)

usage() {
  cat <<'USAGE'
Usage:
  bash scripts/sync-github-production-env.sh [--dry-run|--apply] [options]

Options:
  --env-file PATH        Source env file. Default: .env
  --example-file PATH    Contract env file. Default: .env.example
  --repo OWNER/REPO      GitHub repository. Default: detected from git remote or GH_REPO
  --env NAME             GitHub Environment name. Default: production
  --secrets-only         Sync only GitHub Environment secrets
  --variables-only       Sync only GitHub Environment variables
  --generate-env ENV     Generate a derivative .env or .env.production file for the given env (development|production)
  --gen-dry-run          Run generation in dry-run mode without modifying files
  --dry-run              Validate and print key classification without writing. Default.
  --apply                Write to GitHub using gh secret set / gh variable set
  -h, --help             Show this help

The script never prints values. It syncs keys from an env file into a GitHub
Environment so the production deploy workflow can regenerate the remote .env.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env-file)
      ENV_FILE="$2"
      shift 2
      ;;
    --example-file)
      EXAMPLE_FILE="$2"
      shift 2
      ;;
    --repo)
      REPO="$2"
      shift 2
      ;;
    --env|--environment)
      GITHUB_ENVIRONMENT="$2"
      shift 2
      ;;
    --dry-run)
      MODE="dry-run"
      shift
      ;;
    --apply)
      MODE="apply"
      shift
      ;;
    --secrets-only)
      SYNC_VARIABLES=0
      shift
      ;;
    --variables-only)
      SYNC_SECRETS=0
      shift
      ;;
    --generate-env)
      GENERATE_TARGET="$2"
      shift 2
      ;;
    --gen-dry-run)
      GENERATE_DRY_RUN=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "error: unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -n "$GENERATE_TARGET" ]]; then
  if [[ "$GENERATE_TARGET" != "production" && "$GENERATE_TARGET" != "development" ]]; then
    echo "error: --generate-env target must be 'production' or 'development'." >&2
    exit 1
  fi
fi

if [[ "$SYNC_SECRETS" -eq 0 && "$SYNC_VARIABLES" -eq 0 ]]; then
  echo "error: cannot combine --secrets-only and --variables-only." >&2
  exit 2
fi

ENV_FILE="$(cd "$(dirname "$ENV_FILE")" && pwd)/$(basename "$ENV_FILE")"
EXAMPLE_FILE="$(cd "$(dirname "$EXAMPLE_FILE")" && pwd)/$(basename "$EXAMPLE_FILE")"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "error: env file not found: $ENV_FILE" >&2
  exit 1
fi

if [[ ! -f "$EXAMPLE_FILE" ]]; then
  echo "error: example env file not found: $EXAMPLE_FILE" >&2
  exit 1
fi

declare -A ENV_VALUES=()
declare -A ENV_SEEN=()
declare -A EXAMPLE_SEEN=()
declare -a ENV_KEYS=()
declare -a EXAMPLE_KEYS=()
declare -a DUPLICATE_KEYS=()
declare -A IS_SECRET=()
declare -A IS_REQUIRED_SECRET=()
declare -A IS_REQUIRED_VARIABLE=()

for key in "${SECRET_KEYS[@]}"; do
  IS_SECRET["$key"]=1
done

for key in "${REQUIRED_SECRET_KEYS[@]}"; do
  IS_REQUIRED_SECRET["$key"]=1
done

for key in "${REQUIRED_VARIABLE_KEYS[@]}"; do
  IS_REQUIRED_VARIABLE["$key"]=1
done

# ==========================================
# ENV GENERATION AUTOMATION
# ==========================================

get_invariant_value() {
  local target_env="$1"
  local key="$2"
  
  if [[ "$key" == "APP_ENV" ]]; then
    echo "$target_env"
    return 0
  fi
  
  if [[ "$target_env" == "production" ]]; then
    case "$key" in
      INTERNAL_API_URL|WRAP_API_BASE|AUDIT_INTERNAL_API_BASE)
        echo "http://nginx"
        return 0
        ;;
      AUDIT_RESPONSE_IA_ENABLED)
        echo "0"
        return 0
        ;;
    esac
  fi
  return 1
}

generate_env() {
  local target_env="$1"
  local target_file="$ROOT_DIR/.env"
  if [[ "$target_env" == "production" ]]; then
    target_file="$ROOT_DIR/.env.production"
  fi

  if [[ ! -f "$EXAMPLE_FILE" ]]; then
    echo "error: example env file not found: $EXAMPLE_FILE" >&2
    exit 1
  fi

  declare -A existing_values=()
  if [[ -f "$target_file" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
      line="${line%$'\r'}"
      [[ -z "${line//[[:space:]]/}" ]] && continue
      [[ "$line" =~ ^[[:space:]]*# ]] && continue
      if [[ "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]]; then
        existing_values["${BASH_REMATCH[1]}"]="${BASH_REMATCH[2]}"
      fi
    done < "$target_file"
  fi

  local tmp_file="${target_file}.tmp"
  if [[ "$GENERATE_DRY_RUN" -eq 1 ]]; then
    echo "--- BEGIN DRY RUN ($target_file) ---"
  else
    > "$tmp_file"
  fi

  declare -A final_values=()

  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    if [[ "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]]; then
      local key="${BASH_REMATCH[1]}"
      local val="${BASH_REMATCH[2]}"
      local invariant_val
      
      if invariant_val=$(get_invariant_value "$target_env" "$key"); then
        val="$invariant_val"
      elif [[ -n "${existing_values[$key]+isset}" ]]; then
        val="${existing_values[$key]}"
      fi

      final_values["$key"]="$val"
      if [[ "$GENERATE_DRY_RUN" -eq 1 ]]; then
        printf '%s=%s\n' "$key" "$val"
      else
        printf '%s=%s\n' "$key" "$val" >> "$tmp_file"
      fi
    else
      if [[ "$GENERATE_DRY_RUN" -eq 1 ]]; then
        printf '%s\n' "$line"
      else
        printf '%s\n' "$line" >> "$tmp_file"
      fi
    fi
  done < "$EXAMPLE_FILE"

  if [[ "$GENERATE_DRY_RUN" -eq 1 ]]; then
    echo "--- END DRY RUN ---"
  fi

  local missing=0
  for req_key in "${REQUIRED_SECRET_KEYS[@]}" "${REQUIRED_VARIABLE_KEYS[@]}"; do
    if [[ -z "${final_values[$req_key]:-}" ]]; then
      echo "warning: required key '$req_key' is empty." >&2
      missing=1
    fi
  done

  if [[ "$GENERATE_DRY_RUN" -eq 0 ]]; then
    if [[ -f "$target_file" ]]; then
      cp -a "$target_file" "${target_file}.bak"
      echo "info: backup created at ${target_file}.bak"
    fi
    mv "$tmp_file" "$target_file"
    echo "success: generated $target_file for '$target_env' environment."
  fi

  if [[ $missing -eq 1 ]]; then
    echo "warning: some required keys are missing values. Please fill them out." >&2
  fi
}

if [[ -n "$GENERATE_TARGET" ]]; then
  generate_env "$GENERATE_TARGET"
  exit 0
fi

# ==========================================
# GITHUB ENVIRONMENTS SYNC
# ==========================================

parse_env_file() {
  local file="$1"
  local target="$2"
  local line key value

  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ -z "${line//[[:space:]]/}" ]] && continue
    [[ "$line" =~ ^[[:space:]]*# ]] && continue

    if [[ ! "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]]; then
      echo "error: invalid env line in $file" >&2
      exit 1
    fi

    key="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"

    if [[ "$target" == "env" ]]; then
      if [[ -n "${ENV_SEEN[$key]:-}" ]]; then
        DUPLICATE_KEYS+=("$key")
      else
        ENV_KEYS+=("$key")
      fi
      ENV_SEEN["$key"]=1
      ENV_VALUES["$key"]="$value"
    else
      if [[ -n "${EXAMPLE_SEEN[$key]:-}" ]]; then
        DUPLICATE_KEYS+=("$key")
      else
        EXAMPLE_KEYS+=("$key")
      fi
      EXAMPLE_SEEN["$key"]=1
    fi
  done < "$file"
}

parse_env_file "$ENV_FILE" env
parse_env_file "$EXAMPLE_FILE" example

if [[ "${#DUPLICATE_KEYS[@]}" -gt 0 ]]; then
  printf 'error: duplicate env keys detected: %s\n' "${DUPLICATE_KEYS[*]}" >&2
  exit 1
fi

missing_in_env=()
extra_in_env=()

for key in "${EXAMPLE_KEYS[@]}"; do
  if [[ -z "${ENV_SEEN[$key]:-}" ]]; then
    missing_in_env+=("$key")
  fi
done

for key in "${ENV_KEYS[@]}"; do
  if [[ -z "${EXAMPLE_SEEN[$key]:-}" ]]; then
    extra_in_env+=("$key")
  fi
done

if [[ "${#missing_in_env[@]}" -gt 0 || "${#extra_in_env[@]}" -gt 0 ]]; then
  if [[ "${#missing_in_env[@]}" -gt 0 ]]; then
    printf 'error: keys present in .env.example but missing in .env: %s\n' "${missing_in_env[*]}" >&2
  fi
  if [[ "${#extra_in_env[@]}" -gt 0 ]]; then
    printf 'error: keys present in .env but missing in .env.example: %s\n' "${extra_in_env[*]}" >&2
  fi
  exit 1
fi

secret_keys_to_sync=()
variable_keys_to_sync=()
empty_optional_secrets=()
missing_required=()

for key in "${ENV_KEYS[@]}"; do
  value="${ENV_VALUES[$key]}"
  if [[ -n "${IS_SECRET[$key]:-}" ]]; then
    if [[ -z "$value" && -z "${IS_REQUIRED_SECRET[$key]:-}" ]]; then
      empty_optional_secrets+=("$key")
      continue
    fi
    secret_keys_to_sync+=("$key")
  else
    variable_keys_to_sync+=("$key")
  fi
done

for key in "${REQUIRED_SECRET_KEYS[@]}"; do
  if [[ -z "${ENV_VALUES[$key]:-}" ]]; then
    missing_required+=("$key")
  fi
done

for key in "${REQUIRED_VARIABLE_KEYS[@]}"; do
  if [[ -z "${ENV_VALUES[$key]:-}" ]]; then
    missing_required+=("$key")
  fi
done

if [[ "${#missing_required[@]}" -gt 0 ]]; then
  printf 'error: required production keys have empty values: %s\n' "${missing_required[*]}" >&2
  exit 1
fi

if [[ "$GITHUB_ENVIRONMENT" == "production" && "$MODE" == "apply" ]]; then
  if [[ "${ENV_VALUES[APP_ENV]:-}" != "production" ]]; then
    echo "error: APP_ENV must be production when applying to GitHub Environment production." >&2
    exit 1
  fi

  for url_key in AUDFACT_API_PUBLIC_URL AUDFACT_FRONTEND_PUBLIC_URL; do
    case "${ENV_VALUES[$url_key]:-}" in
      *localhost*|*127.0.0.1*)
        echo "error: $url_key must not point to localhost when applying to production." >&2
        exit 1
        ;;
    esac
  done

  if [[ "${ENV_VALUES[INTERNAL_API_URL]:-}" != "http://nginx" ]]; then
    echo "error: INTERNAL_API_URL must be http://nginx for production Docker runtime." >&2
    exit 1
  fi

  if [[ "${ENV_VALUES[WRAP_API_BASE]:-}" != "http://nginx" ]]; then
    echo "error: WRAP_API_BASE must be http://nginx for production Docker runtime." >&2
    exit 1
  fi
fi

detect_repo() {
  local remote
  remote="$(git -C "$ROOT_DIR" config --get remote.origin.url 2>/dev/null || true)"
  # Strip embedded username (e.g. https://user@github.com/... -> https://github.com/...)
  remote="$(printf '%s' "$remote" | sed -E 's|https://[^@]+@github\.com|https://github.com|')"
  case "$remote" in
    https://github.com/*/*.git)
      remote="${remote#https://github.com/}"
      printf '%s' "${remote%.git}"
      ;;
    https://github.com/*/*)
      printf '%s' "${remote#https://github.com/}"
      ;;
    git@github.com:*.git)
      remote="${remote#git@github.com:}"
      printf '%s' "${remote%.git}"
      ;;
    git@github.com:*)
      printf '%s' "${remote#git@github.com:}"
      ;;
  esac
}

if [[ -z "$REPO" ]]; then
  REPO="$(detect_repo)"
fi

if [[ -z "$REPO" ]]; then
  echo "error: could not detect GitHub repo. Pass --repo OWNER/REPO." >&2
  exit 1
fi

print_list() {
  local label="$1"
  shift
  printf '%s (%s):' "$label" "$#"
  if [[ "$#" -eq 0 ]]; then
    printf ' none\n'
    return
  fi
  printf '\n'
  printf '  %s\n' "$@"
}

echo "GitHub repo: $REPO"
echo "GitHub environment: $GITHUB_ENVIRONMENT"
echo "Mode: $MODE"
echo "Env keys: ${#ENV_KEYS[@]}"
echo "Example keys: ${#EXAMPLE_KEYS[@]}"

if [[ "$SYNC_SECRETS" -eq 1 ]]; then
  print_list "Secrets" "${secret_keys_to_sync[@]}"
fi

if [[ "$SYNC_VARIABLES" -eq 1 ]]; then
  print_list "Variables" "${variable_keys_to_sync[@]}"
fi

if [[ "${#empty_optional_secrets[@]}" -gt 0 ]]; then
  print_list "Skipped empty optional secrets" "${empty_optional_secrets[@]}"
fi

if [[ "$MODE" != "apply" ]]; then
  echo "Dry run only. Re-run with --apply to write to GitHub."
  exit 0
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "error: GitHub CLI gh is required for --apply." >&2
  exit 1
fi

gh auth status >/dev/null

if [[ "$SYNC_SECRETS" -eq 1 ]]; then
  for key in "${secret_keys_to_sync[@]}"; do
    printf '%s' "${ENV_VALUES[$key]}" | gh secret set "$key" --repo "$REPO" --env "$GITHUB_ENVIRONMENT"
    echo "secret set: $key"
  done
fi

if [[ "$SYNC_VARIABLES" -eq 1 ]]; then
  for key in "${variable_keys_to_sync[@]}"; do
    gh variable set "$key" --repo "$REPO" --env "$GITHUB_ENVIRONMENT" --body "${ENV_VALUES[$key]}"
    echo "variable set: $key"
  done
fi

echo "GitHub Environment sync completed."
