#!/usr/bin/env php
<?php
$rules = [
    'php' => [
        [
            'enabled' => true,
            'pattern' => '/^\s*(it|test)\s*\(\s*[\'\"]/m',
            'max_matches' => 1,
            'message' => "Only write ONE test at a time. Red/green/refactor means one test, see it fail, make it pass, then refactor.",
        ],
        [
            'enabled' => true,
            'pattern' => '/try\s*\{/i',
            'message' => "Don't add try/catch blocks - exceptions are *exceptional* and should trigger Sentry. Use debug logging to diagnose test issues.",
        ],
        [
            'enabled' => true,
            'pattern' => '/\b(throw\s|throw_if|throw_unless)\b/i',
            'message' => "Don't hand-throw exceptions. Explain what you're trying to do and ask the user.",
        ],
        [
            'enabled' => true,
            'pattern' => '/(Mock::|mock\(|Mockery)/i',
            'message' => "Don't use Mockery mocks - use Laravel's fakes instead.",
        ],
        [
            'enabled' => true,
            'pattern' => '/\bspy\s*\(/i',
            'message' => "Don't use spies - stop and ask the user what to do.",
        ],
        [
            'enabled' => true,
            'pattern' => '/DB::/i',
            'message' => "Don't use raw query builder calls - use Eloquent and model relations. Ask the user if you really need a raw call.",
        ],
        [
            'enabled' => true,
            'pattern' => '/assertDatabase/i',
            'message' => "Don't use raw database assertions - use Eloquent to verify domain/business logic works. Ask the user if you really need this.",
        ],
        [
            'enabled' => true,
            'pattern' => '/->select\(/i',
            'message' => "Don't use select() calls - just grab data from returned models. Selects are premature optimisation.",
        ],
    ],
    '.blade.php' => [
        [
            'enabled' => true,
            'pattern' => '/@php/i',
            'message' => "Don't use @php blocks in templates - pass data from controller/component in the required format.",
        ],
        [
            'enabled' => true,
            'pattern' => '/flux:field/i',
            'message' => "Are you trying to use a flux:field rather than the shorthand flux:input syntax?  If you *really* need to use a flux:field stop and ask the user to turn this block off.",
        ],
    ],
    'py' => [
    ],
    'go' => [
    ],
    'js' => [
    ],
];

$input = json_decode(file_get_contents('php://stdin'), true);
$filePath = $input['tool_input']['file_path'] ?? '';
$content = $input['tool_input']['content'] ?? $input['tool_input']['new_string'] ?? '';

// Determine file type from extension (check compound extensions first)
if (str_ends_with(strtolower($filePath), '.blade.php')) {
    $extension = '.blade.php';
} else {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
}

// No rules for this file type? Allow it.
if (!isset($rules[$extension])) {
    exit(0);
}

// Check all enabled rules
$violations = [];
foreach ($rules[$extension] as $rule) {
    if (!$rule['enabled']) {
        continue;
    }
    if (isset($rule['max_matches'])) {
        $count = preg_match_all($rule['pattern'], $content);
        if ($count > $rule['max_matches']) {
            $violations[] = $rule['message'];
        }
    } else {
        if (preg_match($rule['pattern'], $content)) {
            $violations[] = $rule['message'];
        }
    }
}

// Report violations
if ($violations) {
    foreach ($violations as $msg) {
        fwrite(STDERR, "❌ Blocked: {$msg}\n");
    }
    exit(2);
}

exit(0);
