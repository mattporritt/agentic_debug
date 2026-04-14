<?php

declare(strict_types=1);

$operation = 'reindex';
$verbose = true;

throw new RuntimeException("Smoke fixture CLI failure during {$operation}, verbose=" . ($verbose ? '1' : '0'));
