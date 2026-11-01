<?php

use Crunz\Schedule;

$arguments = $this->arguments;
$schedule = new Schedule();
$expression = null;
$event = $schedule->run(function () use ($arguments, &$expression) {
    $_SERVER['argv'][3] = $arguments['db'];
    $_SERVER['argv'][5] = $arguments['client_url'];
    require dirname(__FILE__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
    echo json_encode([
        'source' => $arguments['source'],
        'db' => $arguments['db'],
        'client_url' => $arguments['client_url'],
        'environment' => getenv('CRUNZ_TEST_VALUE'),
        'environment_priority' => getenv('CRUNZ_TEST_PRIORITY'),
        'expression' => $expression,
        'bootstrap' => $bootstrapped,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
});
$expression = $event->everyTenMinutes()->getExpression();
return $schedule;
