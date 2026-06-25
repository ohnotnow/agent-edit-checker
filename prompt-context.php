#!/usr/bin/env php
<?php
// prompt-context.php — a UserPromptSubmit hook. Unlike check.php / tool-use.php
// it never blocks: it reads the submitted prompt, and for each matching rule it
// writes a block of guidance to STDOUT. On exit 0 that stdout is added to the
// agent's context for the turn. So: STDOUT (not STDERR), and always exit 0.
$rules = [
    [
        'enabled' => true,
        'pattern' => '/\?/',
        'message' => <<<'TXT'
A question mark slipped into this prompt — pause before you reach for a tool. Decide first: is the user asking to understand / weigh / decide, or telling you to change something?
- Question, or you're not sure → answer in words only this turn. Lay out the options, give your recommendation, then stop and ask if they want you to go ahead. No files touched, no tests written, no scaffolding spun up.
- Build only on an unmistakable instruction in THIS message ("do it", "add the test", "go ahead"). A green light from an earlier turn does not carry forward.
- If you're torn, treat it as a question. "What are the trade-offs?" is not a work order.
TXT,
    ],

    // Disabled global example — matches every prompt. See the message for why
    // it's off, and why CLAUDE.md is almost always the better home.
    [
        'enabled' => false,
        'pattern' => '/.*/s', // matches every prompt
        'message' => <<<'TXT'
A global rule. This pattern matches every prompt, so whatever sits in this message would be injected on every single turn.

It's disabled on purpose. Standing rules that should ALWAYS apply belong in CLAUDE.md — that file is already in context every turn, so repeating it here just duplicates it (and trains the agent to skim injected text). Keep this hook for CONDITIONAL nudges, like the question rule above, where the injection only shows up when it's actually relevant.

The one honest exception: if a key instruction tends to drift out of attention over a long session, a periodic re-injection here can keep it fresh. If that is genuinely what you want, edit this message and flip 'enabled' to true. Otherwise, reach for CLAUDE.md.
TXT,
    ],
];

$input = json_decode(file_get_contents('php://stdin'), true);
$prompt = $input['prompt'] ?? '';

$blocks = [];
foreach ($rules as $rule) {
    if (!$rule['enabled']) {
        continue;
    }
    if (preg_match($rule['pattern'], $prompt)) {
        $blocks[] = $rule['message'];
    }
}

if ($blocks) {
    echo implode("\n\n", $blocks) . "\n";
}

exit(0);
