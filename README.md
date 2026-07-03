# Agent Edit Checker

Tiny guardrail scripts for Claude Code. They inspect proposed edits and shell commands, blocking anything that matches rules you define.

The scripts:
- **`check.php`** — screens file edits (Write/Edit) by file extension + regex
- **`tool-use.php`** — screens shell commands (Bash) by command pattern + regex
- **`tool-fails.php`** — logs failed tool calls so you can spot recurring failures (passive — it never blocks anything)
- **`prompt-context.php`** - allows you to inject extra context to your prompt based on regex matches
- **`state-nudge.php`** — tracks files the agent has edited but not re-read, and nudges it to re-read them when the count crosses a threshold (passive — it never blocks anything)

Plus **`install.php`**, which wires them all into your global Claude Code settings for you.

## Installation

Clone or download the scripts somewhere, then run the installer:

```bash
php install.php
```

It adds all four hooks to your global `~/.claude/settings.json` and makes the scripts executable. Before it writes anything it:

- backs your settings up to `settings.json.backup-<timestamp>`,
- merges with any hooks you already have — it never duplicates or clobbers them,
- updates the path in place if one of our hooks is already installed but points elsewhere,
- prints the plan and asks for confirmation.

The command paths it writes are `~`-relative when the repo lives under your home directory (handy if you ever share your settings), or absolute otherwise. Restart Claude Code (or start a new session) for the hooks to take effect.

Flags:
- `--dry-run` — show the plan and change nothing.
- `--settings=/path/to/settings.json` — target a different file, e.g. a project's `.claude/settings.json`.

Prefer to wire it up by hand? See [Manual setup](#manual-setup) below.

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

### prompt-context.php (prompt context)
- Reads JSON from stdin (the hook payload).
- Scans the incoming content for any enabled rule patterns.
- If a match is found - adds the rules additional content to the prompt and then submits it to claude as usual.

### state-nudge.php (state-load nudge)
A `PostToolUse` hook (matcher `Write|Edit|Read`) built on a finding from the world-model-collapse paper (arXiv 2606.31399): an agent's internal picture of the files it's juggling goes stale *silently*, before any action visibly fails — so "should I re-check?" can't be left to the agent's judgement. This hook makes the trigger deterministic instead:

- `Write`/`Edit` marks a file **dirty**; `Read` clears it (as does the file being deleted — a deleted file is no longer live state). The dirty set is tracked per session in `state/<session_id>.json` (subagents get their own file, keyed by `agent_id`, so a busy subagent neither pollutes the parent's count nor misses its own nudge).
- When the dirty set reaches the model's threshold, the hook injects the set itself into the agent's context — each path, how stale it is, and an instruction to re-read — then disarms. It re-arms only once re-reading shrinks the set back below the threshold, so an agent that re-reads as it goes never hears from it at all. Rare firing is deliberate: a nudge that fires constantly becomes wallpaper.
- The threshold is **per-model**, because drift-proneness isn't uniform: `haiku` fires at 5, `opus` at 7, `fable`/`mythos` at 10, and anything unrecognised (including all future models) at the default 5 until it earns a longer leash. The model is sniffed from the tail of the session's `transcript_path` — but only once the set has already reached the default threshold, so the quiet path never reads the transcript. Subagent calls always get the default 5, unsniffed. Each firing logs its threshold and detected model, so the numbers can be tuned from evidence later.
- On the (rare) firing path only, it also walks up from the session's `cwd` looking for an `ait` (agent issue tracker) database and appends the in-progress issue(s) to the nudge. Missing db, missing binary, timeout, bad JSON — the ait clause is silently omitted.
- Firings are logged to `state-nudge.log`; state files from long-dead sessions are pruned opportunistically.

Some imprecision is deliberate (see the comment in the script): `grep`/`cat` glances don't clear the dirty flag, and a brand-new `Write` counts as dirty. Resist the urge to add a taxonomy of edit types — the counter is meant to be cheap and legible.

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

## Manual setup
`install.php` does all of this for you, but if you'd rather wire it up by hand: add the guardrails as `PreToolUse` hooks and the failure logger as a `PostToolUseFailure` hook (no matcher, so it catches every tool):

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
    "PostToolUse": [
      {
        "matcher": "Write|Edit|Read",
        "hooks": [
          {
            "type": "command",
            "command": "/path/to/agent-edit-checker/state-nudge.php"
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
- All three hook scripts expect JSON on stdin from the Claude Code hook system. (`install.php` is the exception — it's a one-off CLI you run yourself, not a hook.)
- `check.php` reads `tool_input.file_path` and either `tool_input.content` or `tool_input.new_string`.
- `tool-use.php` reads `tool_input.command`.
- `tool-fails.php` reads `tool_name`, `tool_input`, `error`, and `is_interrupt`.
- `state-nudge.php` reads `session_id`, `agent_id`, `transcript_path`, `cwd`, `tool_name`, and `tool_input.file_path`. Unlike the others, its output is the JSON `hookSpecificOutput.additionalContext` envelope on stdout — for `PostToolUse`, plain stdout text does **not** reach the agent.
- For the `PreToolUse` guardrails, exit code `0` allows the action and exit code `2` blocks it. `tool-fails.php` and `state-nudge.php` are passive and always exit `0`.
