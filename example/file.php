<?php

declare(strict_types=1);

include_once __DIR__ . '/../vendor/autoload.php';

\P\IO::File()->watch(__DIR__);

\P\run();
