<?php

include_once __DIR__ . '/../vendor/autoload.php';

\P\IO::File()->watch(__DIR__);

\P\run();
