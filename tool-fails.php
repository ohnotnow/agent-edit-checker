#!/usr/bin/env php
<?php
// PostToolUseFailure hook: append one line per failed tool call to
// tool-fails.log, to build up empirical data on what fails repeatedly.
$input = json_decode(file_get_contents('php://stdin'), true);

// is_interrupt means the user hit escape - not a real tool failure, so skip.
if (!empty($input['is_interrupt'])) {
    exit(0);
}

$timestamp = date('c');
$toolName  = $input['tool_name'] ?? 'unknown';
// Bash gives us 'command'; Write/Edit/Read give 'file_path'; anything else
// falls back to the raw input so the failure is still legible in the log.
$detail = $input['tool_input']['command']
       ?? $input['tool_input']['file_path']
       ?? json_encode($input['tool_input'] ?? []);
$error  = $input['error'] ?? '';
$logPath = __DIR__ . '/tool-fails.log';

// Flatten newlines so each failure stays on a single log line.
$logDetail = str_replace(["\r", "\n"], ['\r', '\n'], $detail);
$logError  = str_replace(["\r", "\n"], ['\r', '\n'], $error);
@file_put_contents($logPath, "{$timestamp} | {$toolName} | {$logDetail} | {$logError}\n", FILE_APPEND | LOCK_EX);

exit(0);
