#!/usr/bin/env php
<?php
// Human co-author trailers are fine - this only matches AI/vendor attribution.
$aiAttributionPattern = '/co-authored-by:.*\b(claude|anthropic|openai|codex|gpt)\b|generated with.*\bclaude\b|noreply@anthropic\.com/i';

// Shared with check.php, which applies the same patterns to file content.
$emDashRules = require __DIR__ . '/em-dash-patterns.php';

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
        'command_pattern' => '/\bcomposer\s/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                // Subcommand must directly follow the tool - a bare substring
                // match here blocked any composer call whose command text
                // merely contained "update" somewhere (e.g. in a commit
                // message or file name).
                'pattern' => '/\bcomposer\s+(require|update)\b/',
                'message' => "Never run composer require or composer update without explicit permission. Ask first.",
            ],
        ],
    ],
    'npm' => [
        'command_pattern' => '/\bnpm\s/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                'pattern' => '/\bnpm\s+(install|update)\b/',
                'message' => "Never run npm install or update without explicit permission. Ask first.",
            ],
        ],
    ],
    'pypi' => [
        'command_pattern' => '/\b(uv|pip|pip3)\s/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                // Anchored to the real install forms. The old bare
                // '/(add|install)/' fired on the substring "add" anywhere in
                // a command that also mentioned uv - e.g. 'uv run pytest'
                // bundled with 'ant add', or scripts/add_occasion.py.
                'pattern' => '/\b(?:uv\s+add|uv\s+pip\s+install|pip3?\s+install)\b/',
                'message' => "Never install or update a package without explicit permission. Ask first.",
            ],
        ],
    ],
    'env' => [
        // Loose gate - fast triage of commands worth examining.
        'command_pattern' => '/(\.env\b|\bdeclare\b|\bexport\b|\bprintenv\b|\benv\b|config:show)/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                // Tight forbid pattern: env-dump / env-mutate commands must
                // sit at a shell command boundary (start, after ;, &, |,
                // newline, or '(' ), not buried in a string argument like
                // an --description value. The (?=\s|$|\|) lookahead matches
                // the command followed by whitespace, end-of-line, or a
                // pipe - so 'envsubst' and 'environment' don't trigger.
                'pattern' => '/(\.env\b|(?:^|[;\n&|(]\s*)(?:declare|export|printenv|env)(?=\s|$|\|))/',
                'message' => "Never read or modify .env, dump env vars (declare/export/printenv/env), or set new ones without explicit permission. Ask first.",
            ],
        ],
    ],
    'git-commit-attribution' => [
        'command_pattern' => '/git\s+commit/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                'pattern' => $aiAttributionPattern,
                'message' => "Do NOT add AI attribution to commits. No Co-Authored-By trailers, no 'Generated with Claude Code' footers. Re-run the commit with those lines removed.",
            ],
        ],
    ],
    'gh-pr-attribution' => [
        'command_pattern' => '/gh\s+pr\s+(create|edit)/',
        'checks' => [
            [
                'enabled' => true,
                'type' => 'forbid',
                'pattern' => $aiAttributionPattern,
                'message' => "Do NOT add AI attribution to pull requests. No 'Generated with Claude Code' footers, no Co-Authored-By trailers. Re-run with those lines removed.",
            ],
        ],
    ],
    // check.php only ever sees Write and Edit, so a heredoc, a sed -i, a tee or
    // a printf redirect would otherwise drop an em dash into a file without any
    // hook being consulted. Listing the file-writing commands would just be a
    // list to route around, so every command is screened and the forbid
    // patterns do the work. The cost is that searching for existing em dashes
    // is caught too, which is the accepted direction of error.
    'em-dash' => [
        'command_pattern' => '/./s',
        'checks' => array_map(
            fn (array $rule) => ['type' => 'forbid'] + $rule,
            $emDashRules
        ),
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
