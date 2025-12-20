# Agent Edit Checker

Tiny guardrail script for claude code. It inspects proposed file edits and blocks changes that match rules you define (by file extension + regex).

## Installation

Download the script somewhere and do a `chmod +x` on it.

## How it works
- Reads JSON from stdin (e.g., a tool hook payload).
- Detects the file type from `file_path`.
- Scans the incoming content for any enabled rule patterns.
- Prints violations to stderr and exits with code `2` (non-zero) to block the change.

## Custom rules
Rules live in `check.php` under the `$rules` array.

Add a new rule by appending an array with:
- `enabled`: `true` or `false`
- `pattern`: a regex that matches forbidden content
- `message`: what to show when the rule is triggered

Add a new file type by creating a new extension key (e.g., `'py'`, `'js'`) and listing rules under it.

## Claude Code example
Add this as a `PreToolUse` hook so edits are checked before they are applied:

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "~/Documents/code/agent-edit-checker/check.php"
          }
        ]
      }
    ]
  }
}
```

## Notes
- The script expects stdin JSON with `tool_input.file_path` and either `tool_input.content` or `tool_input.new_string`.
- Exit code `0` allows the change; exit code `2` blocks it.
