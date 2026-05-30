#!/usr/bin/env bash
# =============================================================================
# lint-shell-docs.sh — repository shell documentation policy checker.
#
# Purpose:
#   Enforce a minimum documentation baseline on shell scripts in this
#   repository. Designed to be invoked by `make lint-shell-docs` (see
#   Makefile) and the shell-lint CI workflow.
#
# Inputs:
#   - Positional arguments: one or more files or directories. Directories
#     are walked for *.sh files. When no arguments are given, scans the
#     repository default set (.github/scripts, base, server/hooks).
#   - Environment variables:
#       STRICT (0|1, default 1): if 1 (the default), function-level
#         documentation gaps fail the build. Set STRICT=0 to demote them
#         to warnings (useful when staging cleanup work).
#
# Outputs:
#   - Findings printed to stdout in `path:line: LEVEL: message` form.
#   - A summary line printed at the end.
#
# Exit codes:
#   0 — no failures (warnings may be present when STRICT=0).
#   1 — one or more errors detected (or warnings while STRICT=1).
#   2 — usage error.
#
# Policy summary:
#   File-level (ERRORS, always enforced):
#     1. File must begin with a shebang (`#!`) on line 1.
#     2. File must contain at least one substantive comment line in the
#        first 20 lines explaining its purpose. Separator-only comments
#        (e.g. `# ====`) do not count.
#
#   Function-level (ERRORS by default; WARNINGS when STRICT=0):
#     3. Public functions matching `drx::*` must have at least one
#        non-blank comment line immediately preceding the definition.
#        A single blank line between the comment block and the function
#        is allowed.
#
#   Waivers:
#     - A file containing the line `# drx-doc-policy: waived` (within
#       its first 20 lines) skips all file-level and function-level
#       checks for that file.
#     - A function preceded by `# drx-doc-skip` (within 3 lines above,
#       allowing one blank) skips the function-level check.
# =============================================================================
set -euo pipefail

STRICT="${STRICT:-1}"

errors=0
warnings=0
files_checked=0

print_finding() {
    # shellcheck disable=SC2317
    local level="$1" file="$2" line="$3" msg="$4"
    printf '%s:%s: %s: %s\n' "$file" "$line" "$level" "$msg"
}

is_waived_file() {
    # Returns 0 if file has the file-level waiver marker in its header.
    local file="$1"
    head -n 20 "$file" | grep -Eq '^[[:space:]]*#[[:space:]]*drx-doc-policy:[[:space:]]*waived[[:space:]]*$'
}

check_file_header() {
    local file="$1"

    local first
    first="$(head -n 1 "$file" || true)"
    if [[ "$first" != \#!* ]]; then
        print_finding "ERROR" "$file" "1" "missing shebang on first line"
        errors=$((errors + 1))
        return
    fi

    # Look for a substantive comment line in lines 2..20 that is not a
    # separator-only comment and not blank.
    local found="no"
    local lineno=0
    while IFS= read -r raw; do
        lineno=$((lineno + 1))
        # Skip the shebang line itself.
        [[ $lineno -eq 1 ]] && continue
        [[ $lineno -gt 20 ]] && break
        # Strip leading whitespace.
        local trimmed="${raw#"${raw%%[![:space:]]*}"}"
        # Only consider comment lines.
        [[ "$trimmed" != \#* ]] && continue
        # Drop the leading '#' and any single following space for inspection.
        local body="${trimmed#\#}"
        body="${body# }"
        # Reject separator-only lines (e.g. ===== or -----).
        if [[ "$body" =~ ^[=\-]+$ ]] || [[ -z "$body" ]]; then
            continue
        fi
        found="yes"
        break
    done < "$file"

    if [[ "$found" != "yes" ]]; then
        print_finding "ERROR" "$file" "1" "missing purpose comment in first 20 lines"
        errors=$((errors + 1))
    fi
}

check_public_functions() {
    local file="$1"

    # Iterate over lines, tracking previous lines for context.
    local prev1="" prev2="" prev3=""
    local lineno=0
    # shellcheck disable=SC2094
    # SC2094 false positive: print_finding writes to stderr, not "$file".
    while IFS= read -r raw; do
        lineno=$((lineno + 1))

        # Match `drx::namespace::name() {` (with optional whitespace).
        if [[ "$raw" =~ ^drx::[A-Za-z0-9_:]+\(\)[[:space:]]*\{ ]] || \
           [[ "$raw" =~ ^function[[:space:]]+drx::[A-Za-z0-9_:]+ ]]; then

            # Waiver: any of the 3 lines above contains drx-doc-skip.
            if [[ "$prev1" == *"drx-doc-skip"* ]] || \
               [[ "$prev2" == *"drx-doc-skip"* ]] || \
               [[ "$prev3" == *"drx-doc-skip"* ]]; then
                prev3="$prev2"; prev2="$prev1"; prev1="$raw"
                continue
            fi

            # Accept: prev1 is a comment, OR prev1 is blank and prev2 is a comment.
            local trimmed1="${prev1#"${prev1%%[![:space:]]*}"}"
            local trimmed2="${prev2#"${prev2%%[![:space:]]*}"}"
            if [[ "$trimmed1" == \#* ]]; then
                :
            elif [[ -z "$trimmed1" ]] && [[ "$trimmed2" == \#* ]]; then
                :
            else
                if [[ "$STRICT" = "1" ]]; then
                    print_finding "ERROR" "$file" "$lineno" "public function lacks preceding doc comment: ${raw%%\{*}"
                    errors=$((errors + 1))
                else
                    print_finding "WARN" "$file" "$lineno" "public function lacks preceding doc comment: ${raw%%\{*}"
                    warnings=$((warnings + 1))
                fi
            fi
        fi

        prev3="$prev2"; prev2="$prev1"; prev1="$raw"
    done < "$file"
}

scan_file() {
    local file="$1"
    files_checked=$((files_checked + 1))
    if is_waived_file "$file"; then
        return
    fi
    check_file_header "$file"
    check_public_functions "$file"
}

collect_targets() {
    if [[ $# -gt 0 ]]; then
        for arg in "$@"; do
            if [[ -d "$arg" ]]; then
                while IFS= read -r f; do
                    printf '%s\n' "$f"
                done < <(find "$arg" -type f -name '*.sh')
            elif [[ -f "$arg" ]]; then
                printf '%s\n' "$arg"
            else
                printf 'Not a file or directory: %s\n' "$arg" >&2
                exit 2
            fi
        done
        return
    fi

    # Default scan set mirrors the tiers in SHELL_POLICY.md:
    #   - .github/scripts/**/*.sh
    #   - base/*.sh and base/lib/*.sh (NOT base/web or base/vendor)
    #   - server/hooks/**/*.sh
    if [[ -d .github/scripts ]]; then
        find .github/scripts -type f -name '*.sh'
    fi
    if [[ -d base ]]; then
        find base -maxdepth 1 -type f -name '*.sh'
        if [[ -d base/lib ]]; then
            find base/lib -maxdepth 1 -type f -name '*.sh'
        fi
    fi
    if [[ -d server/hooks ]]; then
        find server/hooks -type f -name '*.sh'
    fi
}

main() {
    local targets=()
    while IFS= read -r line; do
        targets+=("$line")
    done < <(collect_targets "$@")

    if [[ ${#targets[@]} -eq 0 ]]; then
        printf 'No shell scripts found to check.\n'
        return 0
    fi

    for f in "${targets[@]}"; do
        scan_file "$f"
    done

    printf 'shell-docs lint: %d file(s) checked, %d error(s), %d warning(s) (STRICT=%s)\n' \
        "$files_checked" "$errors" "$warnings" "$STRICT"

    if [[ "$errors" -gt 0 ]]; then
        return 1
    fi
    if [[ "$STRICT" = "1" ]] && [[ "$warnings" -gt 0 ]]; then
        return 1
    fi
    return 0
}

main "$@"
