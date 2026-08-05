<?php
// The em dash hunt, in one place. check.php applies these to file content and
// tool-use.php applies them to shell commands, because a hook on Write/Edit
// alone is trivially sidestepped by writing the file from the shell instead.
//
// Kept shared rather than copied into both so the two can never drift: the
// looser of two copies would quietly become the way round both of them.
//
// The en dash is in scope too, along with the figure dash and horizontal bar.
// They are the characters you reach for the moment the em dash stops working,
// and a hyphen reads fine in a range like 1939-45. The minus sign U+2212 is
// left alone, since it has honest work to do in maths.
//
// Rules are in check.php's shape (enabled / pattern / message). tool-use.php
// adds its own 'type' => 'forbid' when it maps them into a command check.
//
// Known holes, both beyond what a regex can see: a character assembled by
// arithmetic, and one smuggled in base64 (where the encoding shifts with byte
// alignment, so no single literal catches it).

// Offered to the user, not for the agent to run: it rewrites files in place.
// The file list comes from git rather than a bare grep -r, so that anything
// git ignores stays untouched: vendor/, node_modules/, build output, the .git
// directory, and log files whose whole value is being an accurate record.
// --cached picks up tracked files and --others --exclude-standard adds new
// ones that aren't ignored, so nothing in the working tree is missed. Outside
// a repo, swap that first stage for grep -rlIE with --exclude-dir per
// directory you want spared.
//
// Verified on macOS, where BSD sed differs from GNU: -i needs its empty
// argument, grep -I skips binaries, --null with xargs -0 survives spaces in
// filenames, and LC_ALL=C avoids sed's "illegal byte sequence" on files that
// aren't valid UTF-8. It exits cleanly when there is nothing to fix. The
// characters are derived through pack() so that this recipe doesn't match the
// patterns below, which would leave this file unable to edit itself.
$cleanupHint = <<<'TXT'
If the project is already littered with them, that is the user's call, not yours: ask them to run  D=$(php -r 'echo pack("H*","e28093")."|".pack("H*","e28094");'); git ls-files -z --cached --others --exclude-standard | LC_ALL=C xargs -0 grep -lIE --null "$D" | LC_ALL=C xargs -0 sed -i '' -E "s/$D/-/g"
TXT;

return [
    [
        'enabled' => true,
        // The literal characters, matched as raw UTF-8 bytes rather than with
        // the /u modifier: preg_* returns false (no match, no error) on invalid
        // UTF-8, which would turn any malformed file into a free pass.
        // U+2012 to U+2015 are the figure dash, en dash, em dash and
        // horizontal bar; then U+2E3A and U+2E3B the two- and three-em dash,
        // U+FE58 the small em dash, and U+FE31 and U+FE32 the vertical forms.
        'pattern' => '/\xE2\x80[\x92-\x95]|\xE2\xB8[\xBA\xBB]|\xEF\xB9\x98|\xEF\xB8[\xB1\xB2]/',
        'message' => "No em or en dashes. Use a plain hyphen, a comma, a colon, or two shorter sentences. Don't reach for a different dash character or another encoding of the same one, those are blocked too. " . $cleanupHint,
    ],
    [
        'enabled' => true,
        // The same characters spelled out: HTML entities (named, decimal, hex),
        // string escapes in every syntax that has one, escaped byte triples in
        // hex and octal, and construction from the code point at runtime.
        'pattern' => '/&(?:mdash|ndash|horbar)\b;?'
            . '|&#0*(?:821[0-3]|1183[45]|6507[34]|65112)\b;?'
            . '|&#[xX]0*(?:201[2-5]|2E3[AB]|FE3[12]|FE58)\b;?'
            . '|\\\\u\{?0*(?:201[2-5]|2E3[AB]|FE3[12]|FE58)\}?'
            . '|\\\\x\{0*(?:201[2-5]|2E3[AB]|FE3[12]|FE58)\}'
            . '|\\\\N\{(?:(?:EM|EN|FIGURE)[ _-]?DASH|HORIZONTAL[ _-]?BAR)\}'
            . '|\\\\0*201[2-5]\b'
            . '|\\\\x?e2\\\\x?80\\\\x?9[2-5]'
            . '|\\\\342\\\\200\\\\22[2-5]'
            . '|\b(?:mb_)?chr\s*\(\s*(?:821[0-3]|0x201[2-5])\s*\)'
            . '|\bfromChar(?:Code|CodePoint)\s*\(\s*(?:821[0-3]|0x201[2-5])'
            . '/i',
        'message' => "That's a dash in disguise, whether an HTML entity, a string escape, or construction from the code point. Same answer: a plain hyphen, a comma, a colon, or two sentences. " . $cleanupHint,
    ],
];
