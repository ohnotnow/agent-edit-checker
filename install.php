#!/usr/bin/env php
<?php
// install.php — wire the agent-edit-checker guardrail hooks into your global
// ~/.claude/settings.json. It backs the file up first, merges cleanly with any
// hooks you already have, and never creates duplicates.
//
// It finds its sibling hook scripts (check.php and friends) by its own
// location (__DIR__), so you can run it from anywhere:
//
//   php install.php             inspect, show the plan, then ask before writing
//   php install.php --dry-run   show the plan only; never touch any file
//   php install.php --settings=/path/to/settings.json   target a different file
//
// The command paths it writes are ~-relative when the clone lives under your
// home directory (nice and shareable), or absolute if you've cloned it
// somewhere like /Volumes/MyCodeDrive/agent-edit-checker.
//
// Settings are parsed into objects (not associative arrays) so that empty JSON
// objects elsewhere in your file — "mcpServers": {} and the like — survive the
// round-trip instead of silently turning into [].

$args = array_slice($argv, 1);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1400));
    exit(0);
}

$dryRun = in_array('--dry-run', $args, true);

$home = getenv('HOME');
if ($home === false || $home === '') {
    fwrite(STDERR, "Couldn't determine your home directory (\$HOME is unset).\n");
    exit(1);
}

$scriptDir = __DIR__;

// Where to write. Defaults to the global settings; --settings=PATH overrides it.
$settingsPath = $home . '/.claude/settings.json';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--settings=')) {
        $settingsPath = substr($arg, strlen('--settings='));
        if (str_starts_with($settingsPath, '~/')) {
            $settingsPath = $home . substr($settingsPath, 1);
        }
    }
}

// The hooks we install. matcher === null means "no matcher" (all tools).
$targets = [
    ['event' => 'PreToolUse',         'matcher' => 'Write|Edit',      'script' => 'check.php'],
    ['event' => 'PreToolUse',         'matcher' => 'Bash',            'script' => 'tool-use.php'],
    ['event' => 'PostToolUse',        'matcher' => 'Write|Edit|Read', 'script' => 'state-nudge.php'],
    ['event' => 'PostToolUseFailure', 'matcher' => null,              'script' => 'tool-fails.php'],
    ['event' => 'UserPromptSubmit',   'matcher' => null,              'script' => 'prompt-context.php'],
];

// Build the command we'll write for a script: a ~-relative path when the clone
// lives under $HOME, an absolute path otherwise.
function command_for(string $scriptDir, string $home, string $script): string
{
    $absolute = $scriptDir . '/' . $script;
    if (str_starts_with($absolute, $home . '/')) {
        return '~' . substr($absolute, strlen($home));
    }
    return $absolute;
}

// --- 1. the scripts must exist; note any that aren't executable yet ----------

$chmodNeeded = [];
foreach ($targets as $t) {
    $path = $scriptDir . '/' . $t['script'];
    if (!is_file($path)) {
        fwrite(STDERR, "Expected to find {$t['script']} next to install.php, but it's missing.\n");
        fwrite(STDERR, "(install.php should live in the cloned repo alongside the other scripts.)\n");
        exit(1);
    }
    if (!is_executable($path)) {
        $chmodNeeded[$path] = $t['script'];
    }
}

// --- 2. load existing settings (or start fresh) ------------------------------

$settings = new stdClass();
$settingsExisted = is_file($settingsPath);
if ($settingsExisted) {
    $raw = file_get_contents($settingsPath);
    if (trim($raw) !== '') {
        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Couldn't parse {$settingsPath} as JSON (" . json_last_error_msg() . ") — leaving it untouched.\n");
            exit(1);
        }
        if (!($decoded instanceof stdClass)) {
            fwrite(STDERR, "{$settingsPath} isn't a JSON object at the top level — leaving it untouched.\n");
            exit(1);
        }
        $settings = $decoded;
    }
}

// Make sure there's a "hooks" object to work with, without clobbering one that
// already has content.
if (!isset($settings->hooks)) {
    $settings->hooks = new stdClass();
} elseif ($settings->hooks === [] /* empty array encoding of {} */) {
    $settings->hooks = new stdClass();
} elseif (!($settings->hooks instanceof stdClass)) {
    fwrite(STDERR, "The \"hooks\" key in {$settingsPath} isn't a JSON object — leaving it untouched.\n");
    exit(1);
}

// --- 3. work out the changes (and apply them to our in-memory copy) ----------

$plan = [];
$changes = 0;

foreach ($targets as $t) {
    $event   = $t['event'];
    $script  = $t['script'];
    $desired = command_for($scriptDir, $home, $script);

    if (!isset($settings->hooks->{$event})) {
        $settings->hooks->{$event} = [];
    } elseif (!is_array($settings->hooks->{$event})) {
        fwrite(STDERR, "\"hooks.{$event}\" in {$settingsPath} isn't a JSON array — leaving it untouched.\n");
        exit(1);
    }

    // Is one of our hooks already registered for this event? Match on the
    // script's filename appearing in a command — the basenames are distinct, so
    // there's no risk of one matching another. ($group / $hook are objects, so
    // assigning to them mutates $settings in place.)
    $found = false;
    $changedHere = false;
    foreach ($settings->hooks->{$event} as $group) {
        if (!isset($group->hooks) || !is_array($group->hooks)) {
            continue;
        }
        foreach ($group->hooks as $hook) {
            $cmd = $hook->command ?? '';
            if (is_string($cmd) && str_contains($cmd, $script)) {
                $found = true;
                if ($cmd !== $desired) {
                    $hook->command = $desired;
                    $changedHere = true;
                }
            }
        }
    }

    if ($found) {
        if ($changedHere) {
            $plan[] = "  [update] {$event} → {$script}: path updated to {$desired}";
            $changes++;
        } else {
            $plan[] = "  [ok]     {$event} → {$script}: already present and correct";
        }
        continue;
    }

    // Not there — append a new group, leaving everyone else's hooks alone.
    $group = new stdClass();
    if ($t['matcher'] !== null) {
        $group->matcher = $t['matcher'];
    }
    $hook = new stdClass();
    $hook->type = 'command';
    $hook->command = $desired;
    $group->hooks = [$hook];
    $settings->hooks->{$event}[] = $group;

    $matcherLabel = $t['matcher'] !== null ? " (matcher: {$t['matcher']})" : '';
    $plan[] = "  [add]    {$event}{$matcherLabel} → {$desired}";
    $changes++;
}

// --- 4. report ---------------------------------------------------------------

echo "agent-edit-checker installer\n";
echo "  scripts:  {$scriptDir}\n";
echo "  settings: {$settingsPath}" . ($settingsExisted ? "\n" : " (will be created)\n");
echo "\n";

if ($chmodNeeded) {
    $verb = $dryRun ? 'would make executable' : 'will make executable';
    echo "Permissions: {$verb}: " . implode(', ', $chmodNeeded) . "\n\n";
}

echo "Planned hook changes:\n";
echo implode("\n", $plan) . "\n\n";

$hasWork = $changes > 0 || count($chmodNeeded) > 0;
if (!$hasWork) {
    echo "Everything's already wired up correctly — nothing to do. 👍\n";
    exit(0);
}

if ($dryRun) {
    echo "Dry run — no files were changed.\n";
    exit(0);
}

// --- 5. confirm, then do it --------------------------------------------------

$willDo = $changes > 0
    ? "back up and update {$settingsPath}"
    : "update file permissions only";
echo "Proceed? This will {$willDo}. [y/N] ";
$answer = strtolower(trim((string) fgets(STDIN)));
if ($answer !== 'y' && $answer !== 'yes') {
    echo "Aborted — nothing changed.\n";
    exit(0);
}

foreach ($chmodNeeded as $path => $script) {
    if (!@chmod($path, 0755)) {
        fwrite(STDERR, "Warning: couldn't make {$script} executable — you may need to chmod +x it yourself.\n");
    }
}

if ($changes > 0) {
    if ($settingsExisted) {
        $backup = $settingsPath . '.backup-' . date('Ymd-His');
        if (!@copy($settingsPath, $backup)) {
            fwrite(STDERR, "Couldn't write a backup to {$backup} — aborting before touching settings.\n");
            exit(1);
        }
        echo "Backed up existing settings to {$backup}\n";
    } else {
        $dir = dirname($settingsPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            fwrite(STDERR, "Couldn't create {$dir} — aborting.\n");
            exit(1);
        }
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Failed to encode the settings JSON — aborting (your file is untouched).\n");
        exit(1);
    }

    // Write to a sibling temp file then rename, so a half-written file can never
    // clobber your real settings.
    $tmp = $settingsPath . '.tmp';
    if (file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $settingsPath)) {
        @unlink($tmp);
        fwrite(STDERR, "Failed to write {$settingsPath} (your backup is safe).\n");
        exit(1);
    }
    echo "Updated {$settingsPath}\n";
}

echo "\nDone. Restart Claude Code (or start a new session) for the hooks to take effect.\n";
