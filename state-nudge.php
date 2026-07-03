#!/usr/bin/env php
<?php
// state-nudge.php — a PostToolUse hook (matcher: Write|Edit|Read). It watches
// how many files the agent has edited but not re-read, and when that "dirty
// set" crosses a threshold it injects the set itself back into the agent's
// context — the files, and how stale each one is — with an instruction to
// re-read them.
//
// Why event-triggered rather than leaving it to the agent's judgement: an
// agent's internal world-state goes wrong silently, *before* its actions do
// (arXiv 2606.31399) — so any "should I re-check?" decision is made by the
// already-drifted component. The trigger here is a deterministic count, and
// the injection is the state itself, not a generic "check your notes" (which
// habituates into wallpaper).
//
// Mechanics:
// - Write/Edit marks a file dirty; Read clears it. Per-session state lives in
//   state/<session_id>.json (plus the agent_id for subagent calls, so a
//   subagent gets its own dirty set — and its own nudge, in its own
//   transcript — without polluting the parent's count).
// - Fires once when the dirty set reaches $dirtyThreshold, then disarms.
//   Re-arms only when re-reading shrinks the set back below the threshold.
//   A session that re-reads as it goes never fires at all — rare firing is
//   load-bearing.
// - Only the rare firing path does anything slow: it looks for an .ait/ db
//   upward from the session's cwd and appends the in-progress issue(s) to the
//   nudge. On any failure (no db, no ait binary, timeout, bad JSON) the ait
//   clause is simply omitted — never a generic fallback line.
//
// Deliberate imperfections — noted so nobody "fixes" them into complexity:
// - grep/cat/head glances do NOT clear the dirty flag: a keyhole view is not
//   a world-state refresh.
// - a wholesale Write of a brand-new file marks it dirty even though the
//   agent authored every byte — over-cautious, fine.
// - a partial Read (offset/limit) DOES clear the flag — over-generous, fine.
// No taxonomy of edit types. The counter is meant to be cheap and legible.
//
// Output contract (verified against code.claude.com/docs/en/hooks, 2026-07-03):
// PostToolUse injects context ONLY via JSON on stdout —
//   {"hookSpecificOutput": {"hookEventName": "PostToolUse", "additionalContext": "..."}}
// hookEventName is required. Plain stdout text does NOT inject for PostToolUse
// (unlike UserPromptSubmit, where prompt-context.php uses bare stdout).
// PostToolUse fires only on tool *success*, so a failed Read never wrongly
// clears a dirty flag. Always exit 0 — this hook never blocks anything.

$dirtyThreshold = 5;          // fire when this many files are edited-but-not-reread
$aitTimeoutSeconds = 3;       // give up on the ait lookup after this long
$staleStateAgeSeconds = 48 * 3600; // old session state files are pruned on the firing path

$stateDir = __DIR__ . '/state';
$logPath = __DIR__ . '/state-nudge.log';

$input = json_decode((string) file_get_contents('php://stdin'), true);
if (!is_array($input)) {
    exit(0);
}

$toolName = $input['tool_name'] ?? '';
$filePath = $input['tool_input']['file_path'] ?? '';
$sessionId = $input['session_id'] ?? '';
$agentId = $input['agent_id'] ?? ''; // present only when the call came from a subagent
$cwd = $input['cwd'] ?? getcwd();

// Defensive: the installer sets a Write|Edit|Read matcher, but don't rely on it.
if ($sessionId === '' || $filePath === '' || !in_array($toolName, ['Write', 'Edit', 'Read'], true)) {
    exit(0);
}

if (!is_dir($stateDir) && !@mkdir($stateDir, 0755, true)) {
    exit(0);
}

$stateKey = preg_replace('/[^A-Za-z0-9._-]/', '_', $sessionId . ($agentId !== '' ? '--' . $agentId : ''));
$stateFile = $stateDir . '/' . $stateKey . '.json';

// --- read-modify-write the state under an exclusive lock ---------------------
// (parallel tool calls mean two of these hooks can run at once)

$fh = @fopen($stateFile, 'c+');
if ($fh === false) {
    exit(0);
}
flock($fh, LOCK_EX);

$state = json_decode((string) stream_get_contents($fh), true);
if (!is_array($state) || !is_array($state['dirty'] ?? null)) {
    $state = ['dirty' => [], 'armed' => true];
}

if ($toolName === 'Read') {
    unset($state['dirty'][$filePath]);
} else {
    $state['dirty'][$filePath] = $state['dirty'][$filePath] ?? time();
}

// A dirty file that has since been deleted is no longer live state — and
// because Bash isn't matched, an rm never clears its entry, so left in place
// it would pin the set at the threshold and keep the nudge disarmed for the
// rest of the session (found in live testing). The set is small, so a stat
// per entry costs nothing.
foreach (array_keys($state['dirty']) as $dirtyPath) {
    if (!is_file($dirtyPath)) {
        unset($state['dirty'][$dirtyPath]);
    }
}

$dirtyCount = count($state['dirty']);
if ($dirtyCount < $dirtyThreshold) {
    $state['armed'] = true;
}

$fire = ($state['armed'] ?? true) && $dirtyCount >= $dirtyThreshold;
if ($fire) {
    $state['armed'] = false;
}

ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n");
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

if (!$fire) {
    exit(0);
}

// --- firing path (rare from here on) -----------------------------------------

// Run a command with no shell, capturing stdout; null on failure or timeout.
function run_command(array $cmd, int $timeoutSeconds): ?string
{
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + $timeoutSeconds;
    $out = '';
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return $status['exitcode'] === 0 ? $out : null;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return null;
        }
        usleep(50_000);
    }
}

function find_ait_db(string $dir): ?string
{
    while (true) {
        $candidate = $dir . '/.ait/ait.db';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
}

// The in-progress ait issue(s) as a message clause, or '' when there's nothing
// trustworthy to say.
function ait_clause(string $cwd, int $timeoutSeconds): string
{
    $db = find_ait_db($cwd);
    if ($db === null) {
        return '';
    }
    $json = run_command(['ait', '--db', $db, 'list', '--status', 'in_progress'], $timeoutSeconds);
    if ($json === null) {
        return '';
    }
    $issues = json_decode($json, true)['issues'] ?? null;
    if (!is_array($issues)) {
        return '';
    }
    $lines = [];
    foreach ($issues as $issue) {
        $id = $issue['id'] ?? '';
        if (!is_string($id) || $id === '') {
            continue;
        }
        $title = is_string($issue['title'] ?? null) ? $issue['title'] : '';
        $lines[] = "- {$id}: {$title} (re-read with: ait show {$id})";
    }
    if ($lines === []) {
        return '';
    }
    $noun = count($lines) > 1 ? 'issues' : 'issue';
    return "\n\nAlso re-read your in-progress ait {$noun} to re-anchor on the goal and acceptance criteria:\n" . implode("\n", $lines);
}

function minutes_ago(int $timestamp): string
{
    $minutes = intdiv(max(0, time() - $timestamp), 60);
    return $minutes < 1 ? 'just now' : "{$minutes}m ago";
}

asort($state['dirty']); // oldest edit first — the most-drifted file at the top
$fileLines = [];
foreach ($state['dirty'] as $path => $since) {
    $fileLines[] = "- {$path} (edited " . minutes_ago((int) $since) . ', not re-read since)';
}

$message = "State-load nudge (automatic: your count of edited-but-not-reread files just reached {$dirtyCount}).\n\n"
    . "You are carrying these files as remembered state:\n"
    . implode("\n", $fileLines) . "\n\n"
    . 'Drift is silent — an internal model goes stale before any action visibly fails, '
    . 'so do not trust a feeling of "I remember these fine". Re-read each file above before '
    . 'editing further; a Read clears a file from this set, and this nudge stays quiet '
    . 'while the set stays below ' . $dirtyThreshold . '.'
    . ait_clause((string) $cwd, $aitTimeoutSeconds);

// Housekeeping while we're on the rare path: drop state files from long-dead sessions.
foreach (glob($stateDir . '/*.json') ?: [] as $old) {
    if ($old !== $stateFile && time() - (int) @filemtime($old) > $staleStateAgeSeconds) {
        @unlink($old);
    }
}

$logLine = date('Y-m-d H:i:s') . " | {$stateKey} | fired at {$dirtyCount} | " . implode(', ', array_keys($state['dirty'])) . "\n";
@file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);

echo json_encode([
    'hookSpecificOutput' => [
        'hookEventName' => 'PostToolUse',
        'additionalContext' => $message,
    ],
], JSON_UNESCAPED_SLASHES) . "\n";

exit(0);
