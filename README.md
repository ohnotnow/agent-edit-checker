# Agent Edit Checker

Tiny guardrail scripts for Claude Code. They inspect proposed edits and shell commands, blocking anything that matches rules you define.

Two scripts:
- **`check.php`** — screens file edits (Write/Edit) by file extension + regex
- **`tool-use.php`** — screens shell commands (Bash) by command pattern + regex

## Installation

Download the scripts somewhere and do a `chmod +x` on both.

## How it works

### check.php (file edits)
- Reads JSON from stdin (the hook payload).
- Detects the file type from `file_path` (supports compound extensions like `.blade.php`).
- Scans the incoming content for any enabled rule patterns.
- Prints violations to stderr and exits with code `2` to block the change.

### tool-use.php (shell commands)
- Reads JSON from stdin (the hook payload).
- Matches the command against each rule's `command_pattern`.
- Runs the rule's checks — either `require` (pattern must be present) or `forbid` (pattern must not be present).
- Prints violations to stderr and exits with code `2` to block the command.

## Custom rules

### Edit rules
Rules live in `check.php` under the `$rules` array, keyed by file extension.

Add a new rule by appending an array with:
- `enabled`: `true` or `false`
- `pattern`: a regex that matches forbidden content
- `message`: what to show when the rule is triggered
- `max_matches` (optional): allow up to N matches before triggering

Add a new file type by creating a new extension key (e.g., `'py'`, `'js'`) and listing rules under it.

### Command rules
Rules live in `tool-use.php` under the `$rules` array, keyed by a descriptive name.

Each rule has a `command_pattern` (regex to match the command) and a `checks` array. Each check has:
- `enabled`: `true` or `false`
- `type`: `require` (pattern must match) or `forbid` (pattern must not match)
- `pattern`: a regex to test against the command
- `message`: what to show when the check fails

## Claude Code setup
Add both as `PreToolUse` hooks in your settings:

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "/path/to/agent-edit-checker/check.php"
          }
        ]
      },
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "/path/to/agent-edit-checker/tool-use.php"
          }
        ]
      }
    ]
  }
}
```

## Notes
- Both scripts expect JSON on stdin from the Claude Code hook system.
- `check.php` reads `tool_input.file_path` and either `tool_input.content` or `tool_input.new_string`.
- `tool-use.php` reads `tool_input.command`.
- Exit code `0` allows the action; exit code `2` blocks it.
