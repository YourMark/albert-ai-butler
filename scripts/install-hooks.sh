#!/bin/bash
#
# Install git hooks for the Albert plugin
#

# Get the root directory of the git repository
ROOT_DIR=$(git rev-parse --show-toplevel 2>/dev/null)

if [ -z "$ROOT_DIR" ]; then
    echo "Error: Not inside a git repository."
    exit 1
fi

HOOKS_DIR="$ROOT_DIR/.git/hooks"
SCRIPTS_DIR="$ROOT_DIR/scripts"

# Install pre-commit hook
if [ -f "$SCRIPTS_DIR/pre-commit" ]; then
    cp "$SCRIPTS_DIR/pre-commit" "$HOOKS_DIR/pre-commit"
    chmod +x "$HOOKS_DIR/pre-commit"
    echo "Installed pre-commit hook."
else
    echo "Error: pre-commit script not found in scripts directory."
    exit 1
fi

echo "Git hooks installed successfully."
