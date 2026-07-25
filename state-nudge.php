#!/usr/bin/env php
<?php
// state-nudge.php — a PostToolUse hook (matcher: Write|Edit|Read|Agent). It watches
// how many files the agent has edited but not re-read, and when that "dirty
// set" crosses a threshold it injects the set itself back into the agent's
// context — the files, and how stale each one is — with an instruction to
// re-read them. A second, independent signal — the confidence check — rides
// the same state file; see Mechanics.
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
// - Files under temp prefixes (/tmp, /private, /var/folders) are ignored
//   entirely — scratch output isn't world-state the agent must keep synced.
// - The threshold is per-model: drift-prone models (haiku) get a short leash,
//   models that hold state well (fable) a longer one. The model is sniffed from
//   the transcript tail, and only once the set has already reached the default
//   threshold — the quiet path never reads the transcript.
// - Fires once when the dirty set reaches $dirtyThreshold, then disarms.
//   Re-arms only when re-reading shrinks the set back below the threshold.
//   A session that re-reads as it goes never fires at all — rare firing is
//   load-bearing.
// - The confidence check: $confidenceStreakThreshold consecutive Write/Edit
//   calls without a single Read is the shape of a confident-momentum spiral —
//   a long friction-free streak of editing from the internal model without
//   looking at reality (the session that feels like it's going best is the
//   riskiest). It fires once — the evidence, plus a demand to verify one
//   unverified assertion and sweep recent output for exposure — then disarms.
//   Any Read resets the streak and re-arms it. The demand is deliberately a
//   task, not a question: "are you sure?" can be self-soothed in half a
//   sentence; "verify one thing and say what you checked" cannot, and it
//   leaves an audit trail in the transcript for judging whether this nudge
//   actually works.
// - The test-quality nudge (Laravel projects only): every Write/Edit to a .php
//   file under <laravel-root>/tests/ (root = nearest ancestor dir holding an
//   artisan file) joins a per-session set of distinct test files. When the set
//   reaches $testNudgeThreshold, the hook tells the agent to hand the file
//   list to the test-quality-checker subagent for a fresh-eyes review, framed
//   as WIP so half-built suites aren't dinged for incompleteness. Instead of
//   an armed flag it ratchets: each firing (or skip) pushes the next one a
//   full threshold of NEW test files away, so ignoring it nags again only
//   after the same amount of unreviewed test work — measurable in the log as
//   consecutive firings with no reset between. Launching that subagent (an
//   Agent tool call with the matching subagent_type — hence Agent in the
//   matcher) clears the set and restores the base threshold; no Read ever
//   clears it, because re-reading your own tests is not a fresh-eyes review.
//   If neither ~/.claude/agents/ nor <root>/.claude/agents/ holds the agent's
//   .md file the nudge is skipped and logged — never nudge an agent toward a
//   reviewer that isn't installed. The message rotates between a few phrasings
//   so repeat firings don't habituate into an ignorable fixed banner.
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
// - Bash is invisible here, so a session verifying via tests between edits
//   still grows the confidence streak, and tool failures (friction that would
//   honestly reset it) don't reset it either. Accepted: a false firing costs
//   a minute. If the log shows too many, wire tool-fails.php into the state
//   file — not before.
// - distinct files is a lossy proxy for "tests written": a Pest suite that
//   keeps a whole feature in one file under-counts (and may never fire), while
//   debugging one test method all afternoon correctly counts as a single file.
//   Under-firing is the accepted direction of error here — tune from the log.
// No taxonomy of edit types. The counters are meant to be cheap and legible.
//
// Output contract (verified against code.claude.com/docs/en/hooks, 2026-07-03):
// PostToolUse injects context ONLY via JSON on stdout —
//   {"hookSpecificOutput": {"hookEventName": "PostToolUse", "additionalContext": "..."}}
// hookEventName is required. Plain stdout text does NOT inject for PostToolUse
// (unlike UserPromptSubmit, where prompt-context.php uses bare stdout).
// PostToolUse fires only on tool *success*, so a failed Read never wrongly
// clears a dirty flag. Always exit 0 — this hook never blocks anything.

$defaultDirtyThreshold = 5;   // fire when this many files are edited-but-not-reread
// Per-model overrides — first substring match on the transcript's model id wins;
// unmatched (and future) models get the default. Subagent calls (agent_id set)
// always get the default, unsniffed: short-lived workers don't need a longer
// leash. (If per-model subagent thresholds are ever wanted: a subagent's turns
// are NOT in the parent transcript — they get their own file at
// <projects-dir>/<session-id>/subagents/agent-<agent_id>.jsonl, model on every
// assistant line; the .meta.json sidecar there has NO model. Verified 2026-07-03.)
$modelDirtyThresholds = [
    'haiku' => 5,
    'opus' => 7,
    'fable' => 10,
    'mythos' => 10, // same model as fable, different badge
];
$confidenceStreakThreshold = 12; // consecutive Write/Edits without one Read fires the confidence check — tune from the log
$testNudgeThreshold = 4;      // distinct test files edited since the last fresh-eyes review fires the test-quality nudge — tune from the log
$testAgentName = 'test-quality-checker'; // the reviewing subagent: launching it resets the counter, its absence mutes the nudge
$aitTimeoutSeconds = 3;       // give up on the ait lookup after this long
$staleStateAgeSeconds = 48 * 3600; // old session state files are pruned on the firing path

$stateDir = __DIR__ . '/state';
$logPath = __DIR__ . '/state-nudge.log';

// --- model → threshold ---------------------------------------------------------

// The last claude-* model id in the transcript's tail, or null when undetectable.
// Assistant lines carry message.model; the claude- prefix skips "<synthetic>"
// entries, and last-match-wins honours a mid-session /model switch.
//
// Scans backwards in chunks rather than one fixed 32KB tail: a base64 image
// tool_result (a screenshot Read) is a single ~550KB line, so a small fixed
// window can land entirely inside the blob and see no model line at all.
// Observed live 2026-07-10 (tail-claude session): two "model unknown" firings,
// both minutes after a PNG Read, in a session where the same sniff had
// demonstrably worked earlier — a fable session got the default leash of 5.
// The scan is capped: a transcript with no model line near its end costs a
// few MB of reads at most, then falls back to the default threshold as before.
function detect_model(string $transcriptPath): ?string
{
    $size = @filesize($transcriptPath);
    if ($size === false || $size === 0) {
        return null;
    }
    $fh = @fopen($transcriptPath, 'r');
    if ($fh === false) {
        return null;
    }

    $chunkSize = 256 * 1024;
    $overlap = 256;             // catches a match straddling a chunk boundary
    $maxScan = 4 * 1024 * 1024; // several image blobs deep — beyond that, give up

    $pos = $size;
    $scanned = 0;
    while ($pos > 0 && $scanned < $maxScan) {
        $readFrom = max(0, $pos - $chunkSize);
        fseek($fh, $readFrom);
        $window = (string) fread($fh, min($pos + $overlap, $size) - $readFrom);
        if (preg_match_all('/"model":"(claude-[^"]*)"/', $window, $matches)) {
            fclose($fh);
            // Last match in this window is the latest in file order overall:
            // every window nearer the end has already been scanned matchless.
            return end($matches[1]);
        }
        $scanned += $pos - $readFrom;
        $pos = $readFrom;
    }
    fclose($fh);
    return null;
}

function threshold_for_model(?string $model, array $thresholds, int $default): int
{
    foreach ($thresholds as $needle => $threshold) {
        if ($model !== null && str_contains($model, $needle)) {
            return $threshold;
        }
    }
    return $default;
}

$input = json_decode((string) file_get_contents('php://stdin'), true);
if (!is_array($input)) {
    exit(0);
}

$toolName = $input['tool_name'] ?? '';
$filePath = $input['tool_input']['file_path'] ?? '';
$sessionId = $input['session_id'] ?? '';
$agentId = $input['agent_id'] ?? ''; // present only when the call came from a subagent
$transcriptPath = $input['transcript_path'] ?? '';
$cwd = $input['cwd'] ?? getcwd();

// Defensive: the installer sets a Write|Edit|Read|Agent matcher, but don't rely on it.
if ($sessionId === '') {
    exit(0);
}

// An Agent call matters here only when it launches the test-quality reviewer —
// the honest "a review actually happened" reset for the test-quality nudge.
// Every other subagent launch falls through the guard below and exits.
$isReviewLaunch = $toolName === 'Agent'
    && ($input['tool_input']['subagent_type'] ?? '') === $testAgentName;

if (!$isReviewLaunch && ($filePath === '' || !in_array($toolName, ['Write', 'Edit', 'Read'], true))) {
    exit(0);
}

// Temp-dir files (session scratchpads, ad-hoc /tmp scribbles) are throwaway by
// definition, not remembered world-state — and being write-once they never get
// re-read, so they'd waste the single firing on a low-stakes set and then pin
// the count at the threshold, keeping the nudge disarmed for the session.
// Broad prefixes on purpose: real work never lives here, and this survives the
// scratchpad path format changing. /private is the macOS spelling of /tmp;
// /var/folders is macOS $TMPDIR.
foreach (['/tmp/', '/private/', '/var/folders/'] as $tempPrefix) {
    if (str_starts_with($filePath, $tempPrefix)) {
        exit(0);
    }
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
    $state = ['dirty' => [], 'armed' => true, 'streak' => 0, 'confidence_armed' => true, 'test_files' => [], 'test_next_fire' => $testNudgeThreshold];
}

// Write the state back and release the lock (shared by the reset path's early
// exit and the normal end-of-flow write).
function write_state($fh, array $state): void
{
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

if ($isReviewLaunch) {
    $cleared = count($state['test_files'] ?? []);
    $state['test_files'] = [];
    $state['test_next_fire'] = $testNudgeThreshold;
    write_state($fh, $state);
    if ($cleared > 0) {
        $logLine = date('Y-m-d H:i:s') . " | {$stateKey} | test-nudge-reset ({$testAgentName} launched; {$cleared} test files cleared)\n";
        @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
    }
    exit(0);
}

if ($toolName === 'Read') {
    unset($state['dirty'][$filePath]);
    $state['streak'] = 0; // any Read is friction: it resets (and re-arms) the confidence check
    unset($state['streak_started']);
} else {
    $state['dirty'][$filePath] = $state['dirty'][$filePath] ?? time();
    $state['streak'] = (int) ($state['streak'] ?? 0) + 1;
    if ($state['streak'] === 1) {
        $state['streak_started'] = time();
    }
    // Test-quality counting, gated to Laravel: the artisan walk-up both
    // identifies the project as Laravel and pins down whose tests/ dir this
    // is. The cheap string checks keep the walk off the everyday-edit path.
    if (str_ends_with($filePath, '.php') && str_contains($filePath, '/tests/')) {
        $laravelRoot = find_up(dirname($filePath), 'artisan');
        if ($laravelRoot !== null && str_starts_with($filePath, $laravelRoot . '/tests/')) {
            $state['test_files'][$filePath] = $state['test_files'][$filePath] ?? time();
        }
    }
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
foreach (array_keys($state['test_files'] ?? []) as $testPath) {
    if (!is_file($testPath)) {
        unset($state['test_files'][$testPath]);
    }
}

$dirtyCount = count($state['dirty']);
$streak = (int) ($state['streak'] ?? 0);

// Resolve this call's threshold. Below the default every model behaves the same,
// so the transcript sniff only runs once the set has reached it (and never for
// subagents, which always take the default).
$dirtyThreshold = $defaultDirtyThreshold;
$model = null;
if ($agentId === '' && $dirtyCount >= $defaultDirtyThreshold) {
    $model = detect_model($transcriptPath);
    $dirtyThreshold = threshold_for_model($model, $modelDirtyThresholds, $defaultDirtyThreshold);
}

if ($dirtyCount < $dirtyThreshold) {
    $state['armed'] = true;
}

$dirtyFire = ($state['armed'] ?? true) && $dirtyCount >= $dirtyThreshold;
if ($dirtyFire) {
    $state['armed'] = false;
}

// The confidence check is an independent signal with the same hysteresis:
// fire once at the threshold, disarm, re-arm only via the Read that resets
// the streak. Sessions that look before they leap never hear it.
if ($streak < $confidenceStreakThreshold) {
    $state['confidence_armed'] = true;
}

$confidenceFire = ($state['confidence_armed'] ?? true) && $streak >= $confidenceStreakThreshold;
if ($confidenceFire) {
    $state['confidence_armed'] = false;
}

// The test-quality nudge ratchets rather than arming: crossing the line pushes
// the next firing a full threshold of NEW test files away (whether the nudge
// was delivered or skipped for want of the agent), and only the reviewer
// launch resets the base. The set only grows by one per call, so a crossing
// is always seen.
$testCount = count($state['test_files'] ?? []);
$testFire = false;
$testSkipLogLine = null;
if ($testCount >= (int) ($state['test_next_fire'] ?? $testNudgeThreshold)) {
    $state['test_next_fire'] = (int) ($state['test_next_fire'] ?? $testNudgeThreshold) + $testNudgeThreshold;
    // Never nudge toward a reviewer that isn't installed — check the global
    // agents dir and the project's own. Filename check only (the frontmatter
    // name is the real identity, but a rename-without-rename mismatch fails
    // in the safe direction: silence).
    $home = (string) getenv('HOME');
    $laravelRoot = find_up(dirname((string) array_key_first($state['test_files'])), 'artisan');
    $agentFile = '.claude/agents/' . $testAgentName . '.md';
    if (($home !== '' && is_file($home . '/' . $agentFile)) || ($laravelRoot !== null && is_file($laravelRoot . '/' . $agentFile))) {
        $testFire = true;
        $state['test_fired'] = (int) ($state['test_fired'] ?? 0) + 1;
    } else {
        $testSkipLogLine = date('Y-m-d H:i:s') . " | {$stateKey} | test-nudge-skipped at {$testCount} test files — no {$testAgentName} agent installed (see https://github.com/ohnotnow/agentic-stuff)\n";
    }
}

write_state($fh, $state);

if ($testSkipLogLine !== null) {
    @file_put_contents($logPath, $testSkipLogLine, FILE_APPEND | LOCK_EX);
}

if (!$dirtyFire && !$confidenceFire && !$testFire) {
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

// The nearest ancestor of $dir (inclusive) containing $relativePath, or null.
function find_up(string $dir, string $relativePath): ?string
{
    while (true) {
        if (is_file($dir . '/' . $relativePath)) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
}

function find_ait_db(string $dir): ?string
{
    $root = find_up($dir, '.ait/ait.db');
    return $root === null ? null : $root . '/.ait/ait.db';
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

$messages = [];

if ($dirtyFire) {
    asort($state['dirty']); // oldest edit first — the most-drifted file at the top
    $fileLines = [];
    foreach ($state['dirty'] as $path => $since) {
        $fileLines[] = "- {$path} (first edited " . minutes_ago((int) $since) . ', not re-read since)';
    }
    $messages[] = "State-load nudge (automatic: your count of edited-but-not-reread files just reached {$dirtyCount}).\n\n"
        . "You are carrying these files as remembered state:\n"
        . implode("\n", $fileLines) . "\n\n"
        . 'Drift is silent — an internal model goes stale before any action visibly fails, '
        . 'so do not trust a feeling of "I remember these fine". Re-read each file above before '
        . 'editing further; a Read clears a file from this set, and this nudge stays quiet '
        . 'while the set stays below ' . $dirtyThreshold . '.';

    $modelLabel = $model ?? ($agentId !== '' ? 'subagent-default' : 'unknown');
    $logLine = date('Y-m-d H:i:s') . " | {$stateKey} | fired at {$dirtyCount} (threshold {$dirtyThreshold}, model {$modelLabel}) | " . implode(', ', array_keys($state['dirty'])) . "\n";
    @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
}

if ($confidenceFire) {
    $started = isset($state['streak_started']) ? ' — the first of them ' . minutes_ago((int) $state['streak_started']) : '';
    $messages[] = "Confidence check (automatic: {$streak} consecutive Write/Edit calls without a single Read{$started}).\n\n"
        . 'A long friction-free streak of edits is the exact condition where confident-momentum forms: '
        . "concerns get flagged then flowed past, and the session that feels like it's going best is the riskiest. "
        . "Before the next edit, do two things:\n"
        . "1. Name the riskiest thing you have asserted-but-not-verified during this streak, and verify it now — run the command, read the code. Do not reassure yourself from memory.\n"
        . "2. Sweep what you have written during the streak for exposure: real hostnames, internal IPs, people's names, secrets — none of those belong in code, tests, or notes.\n\n"
        . 'Report briefly what you checked and what you found before carrying on. A bare "all fine" with nothing named is itself the flagged-then-flowed-past tell.';

    $logLine = date('Y-m-d H:i:s') . " | {$stateKey} | confidence-fired at streak {$streak}\n";
    @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
}

if ($testFire) {
    // A few phrasings, rotated per firing — identical repeated text habituates
    // into "seen it, handled it" even when it wasn't handled.
    $phrasings = [
        'A fresh pair of eyes catches the anti-patterns you have stopped seeing — and a habit corrected now is one the rest of the session does not repeat.',
        'From the inside every test you write looks fine; the suite-level tells (overlap, weak assertions, happy-path bias) are only visible cold.',
        'A run of green tests can look like rigour while sharing a single blind spot. A cold reviewer spots it while it is still cheap to fix.',
    ];
    $body = $phrasings[((int) $state['test_fired'] - 1) % count($phrasings)];
    asort($state['test_files']);
    $fileLines = [];
    foreach ($state['test_files'] as $path => $since) {
        $fileLines[] = "- {$path} (first edited " . minutes_ago((int) $since) . ')';
    }
    $messages[] = "Test-quality nudge (automatic: {$testCount} distinct test files edited since the last fresh-eyes review).\n\n"
        . $body . "\n\n"
        . "The test files in question:\n"
        . implode("\n", $fileLines) . "\n\n"
        . "Launch the {$testAgentName} subagent now: hand it this file list and tell it this is a mid-feature WIP review, so it should judge the tests that exist rather than flag incompleteness. "
        . 'Act on any "you really should fix" findings before writing more tests — launching the checker is what resets this counter.';

    $logLine = date('Y-m-d H:i:s') . " | {$stateKey} | test-nudge-fired at {$testCount} test files (firing #" . (int) $state['test_fired'] . ') | ' . implode(', ', array_keys($state['test_files'])) . "\n";
    @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
}

$message = implode("\n\n", $messages) . ait_clause((string) $cwd, $aitTimeoutSeconds);

// Housekeeping while we're on the rare path: drop state files from long-dead sessions.
foreach (glob($stateDir . '/*.json') ?: [] as $old) {
    if ($old !== $stateFile && time() - (int) @filemtime($old) > $staleStateAgeSeconds) {
        @unlink($old);
    }
}

echo json_encode([
    'hookSpecificOutput' => [
        'hookEventName' => 'PostToolUse',
        'additionalContext' => $message,
    ],
], JSON_UNESCAPED_SLASHES) . "\n";

exit(0);
