<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Maguari\Server\Http\App::fromEnvironment()->run();
