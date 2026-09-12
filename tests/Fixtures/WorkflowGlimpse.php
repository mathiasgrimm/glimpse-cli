<?php

file_put_contents(getenv('WORKFLOW_CALLS'), json_encode(array_slice($argv, 1))."\n");

exit((int) getenv('WORKFLOW_STATUS'));
