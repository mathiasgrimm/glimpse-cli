<?php

file_put_contents(getenv('WORKFLOW_CALLS'), json_encode(array_slice($argv, 1))."\n", FILE_APPEND);

if ($argv[1] === 'check') {
    echo file_get_contents(getenv('WORKFLOW_REPORT'));
    exit((int) getenv('WORKFLOW_STATUS'));
}

exit((int) getenv('WORKFLOW_OPTIMIZE_STATUS'));
