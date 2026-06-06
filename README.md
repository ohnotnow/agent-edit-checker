# Agent Edit Checker

Tiny guardrail scripts for Claude Code. They inspect proposed edits and shell commands, blocking anything that matches rules you define.

Three scripts:
- **`check.php`** — screens file edits (Write/Edit) by file extension + regex
- **`tool-use.php`** — screens shell commands (Bash) by command pattern + regex
- **`tool-fails.php`** — logs failed tool calls so you can spot recurring failures (passive — it never blocks anything)

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
- Appends one log line per call to `tool-use.log` in the format `allowed | command` or `denied | command` (for possibly building a harness-level allow/deny list).
- Prints violations to stderr and exits with code `2` to block the command.

### tool-fails.php (failed tool calls)
- Reads JSON from stdin (the `PostToolUseFailure` payload).
- Skips user interrupts (`is_interrupt` is `true`) — that's you pressing escape, not a real failure.
- Appends one line per failure to `tool-fails.log` in the format `timestamp | tool | detail | error` (newlines in the error are flattened to `\n` so each failure stays on one line).
- `detail` is the Bash `command`, falling back to `file_path` for Write/Edit/Read, then the raw tool input for anything else.
- Purely passive: it always exits `0` and never blocks a call. It exists to build up empirical data on what fails repeatedly — dodgy CLI flags, BSD-vs-GNU differences, and the like.

**Caveat — piped commands hide failures.** `PostToolUseFailure` fires on the *tool call's* exit code, and a pipeline reports the exit code of its *last* command. So `some-cmd | head`, `some-cmd | grep …`, or `some-cmd || true` look successful even when `some-cmd` failed, and won't be logged. Bare failing commands are caught fine.

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
Add the guardrails as `PreToolUse` hooks and the failure logger as a `PostToolUseFailure` hook (no matcher, so it catches every tool):

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
    ],
    "PostToolUseFailure": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "/path/to/agent-edit-checker/tool-fails.php"
          }
        ]
      }
    ]
  }
}
```

## Notes
- All three scripts expect JSON on stdin from the Claude Code hook system.
- `check.php` reads `tool_input.file_path` and either `tool_input.content` or `tool_input.new_string`.
- `tool-use.php` reads `tool_input.command`.
- `tool-fails.php` reads `tool_name`, `tool_input`, `error`, and `is_interrupt`.
- For the `PreToolUse` guardrails, exit code `0` allows the action and exit code `2` blocks it. `tool-fails.php` is a logger and always exits `0`.
