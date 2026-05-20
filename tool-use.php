#!/usr/bin/env php
<?php
$rules = [
    'pest' => [
        'command_pattern' => '/(?:^|\s)(?:\.\/vendor\/bin\/)?pest\b/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'require',
                'pattern' => '/\s--compact(\s|$)/',
                'message' => "Pest must be run with --compact to reduce output. Example: ./vendor/bin/pest --compact",
            ],
        ],
    ],
    'composer' => [
        'command_pattern' => '/composer\s/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                'pattern' => '/(require|update)/',
                'message' => "Never run composer require or composer update without explicit permission. Ask first.",
            ],
        ],
    ],
    'npm' => [
        'command_pattern' => '/npm\s/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                'pattern' => '/(install|update)/',
                'message' => "Never run npm install or update without explicit permission. Ask first.",
            ],
        ],
    ],
    'pypi' => [
        'command_pattern' => '/(uv|pip|pip3)\s/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                'pattern' => '/(add|install)/',
                'message' => "Never install or update a package without explicit permission. Ask first.",
            ],
        ],
    ],
    'env' => [
        // Loose gate — fast triage of commands worth examining.
        'command_pattern' => '/(\.env\b|\bdeclare\b|\bexport\b|\bprintenv\b|\benv\b)/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                // Tight forbid pattern: env-dump / env-mutate commands must
                // sit at a shell command boundary (start, after ;, &, |,
                // newline, or '(' ), not buried in a string argument like
                // an --description value. The (?=\s|$|\|) lookahead matches
                // the command followed by whitespace, end-of-line, or a
                // pipe — so 'envsubst' and 'environment' don't trigger.
                'pattern' => '/(\.env\b|(?:^|[;\n&|(]\s*)(?:declare|export|printenv|env)(?=\s|$|\|))/',
                'message' => "Never read or modify .env, dump env vars (declare/export/printenv/env), or set new ones without explicit permission. Ask first.",
            ],
        ],
    ],
];

$input = json_decode(file_get_contents('php://stdin'), true);
$command = $input['tool_input']['command'] ?? '';
$logPath = __DIR__ . '/tool-use.log';

$violations = [];
foreach ($rules as $name => $rule) {
    if (!preg_match($rule['command_pattern'], $command)) {
        continue;
    }
    foreach ($rule['checks'] as $check) {
        if (!$check['enabled']) {
            continue;
        }
        $matched = (bool) preg_match($check['pattern'], $command);
        if ($check['type'] === 'require' && !$matched) {
            $violations[] = $check['message'];
        } elseif ($check['type'] === 'forbid' && $matched) {
            $violations[] = $check['message'];
        }
    }
}

$decision = $violations ? 'denied' : 'allowed';
$logCommand = str_replace(["\r", "\n"], ['\r', '\n'], $command);
@file_put_contents($logPath, "{$decision} | {$logCommand}\n", FILE_APPEND | LOCK_EX);

if ($violations) {
    foreach ($violations as $msg) {
        fwrite(STDERR, "❌ Blocked: {$msg}\n");
    }
    exit(2);
}

exit(0);
