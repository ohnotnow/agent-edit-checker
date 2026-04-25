#!/usr/bin/env php
<?php
$rules = [
    'pest' => [
        'command_pattern' => '/\b(pest|\.\/vendor\/bin\/pest)\b/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'require',
                'pattern' => '/\s--compact(\s|$)/',
                'message' => "Pest must be run with --compact to reduce output. Example: ./vendor/bin/pest --compact",
            ],
        ],
    ],
    'env' => [
        'command_pattern' => '/(\.env\b|\bdeclare\s+-x\b|\bexport\s+\w+)/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                'pattern' => '/(\.env\b|\bdeclare\s+-x\b|\bexport\s+\w+)/',
                'message' => "Never read or modify .env, run declare -x, or export env vars without explicit permission.  Ask first.",
            ],
        ],
    ],
];

$input = json_decode(file_get_contents('php://stdin'), true);
$command = $input['tool_input']['command'] ?? '';

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

if ($violations) {
    foreach ($violations as $msg) {
        fwrite(STDERR, "❌ Blocked: {$msg}\n");
    }
    exit(2);
}

exit(0);
