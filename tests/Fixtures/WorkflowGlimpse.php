<?php

/**
 * Fake command boundary for the generated workflow's executable tests.
 */
file_put_contents(getenv('WORKFLOW_CALLS'), $argv[1]."\n", FILE_APPEND);
$baselinePath = '.glimpse-baseline.json';
$baseline = is_file($baselinePath) ? json_decode(file_get_contents($baselinePath), true) : ['files' => []];

if ($argv[1] === 'check') {
    $report = json_decode(file_get_contents(getenv('WORKFLOW_REPORT')), true);
    $covered = $report['files'] !== [];
    foreach ($report['files'] as $row) {
        $entry = $baseline['files'][$row['file']] ?? null;
        $covered = $covered && $entry !== null && ($entry['xxh128'] ?? '') === hash_file('xxh128', $row['file']);
    }
    if ($covered) {
        $report['files'] = [];
        $report['needs_optimization'] = 0;
    }
    echo json_encode($report);
    exit($covered ? 0 : (int) getenv('WORKFLOW_STATUS'));
}

if ($argv[1] !== 'optimize' || ! in_array('--quality=85', $argv, true)
    || ! in_array('--force', $argv, true) || ! in_array('--output='.$argv[2], $argv, true)) {
    exit(2);
}
$path = $argv[2];
$behavior = getenv('WORKFLOW_BEHAVIOR');
if ($behavior === 'failure') {
    exit(1);
}
$bytes = base64_decode(getenv('WORKFLOW_IMAGE'));
if ($behavior === 'larger') {
    $bytes = file_get_contents($path).'more padding';
} elseif ($behavior === 'wrong-format') {
    $bytes = 'not an image';
} elseif ($behavior === 'unchanged') {
    $bytes = file_get_contents($path);
}
file_put_contents($path, $bytes);
if ($behavior !== 'missing-baseline') {
    $baseline['files'][substr($path, 2)] = [
        'size' => strlen($bytes),
        'xxh128' => $behavior === 'wrong-hash' ? 'bad' : hash('xxh128', $bytes),
        'via' => 'optimize',
    ];
    file_put_contents($baselinePath, json_encode($baseline));
}
echo '{}';
