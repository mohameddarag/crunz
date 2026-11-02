<?php

$schedule = new \Crunz\Schedule();
$schedule->run(function () {
    $delimiterDecoy = ' on line 123';
    trigger_error('isolated closure warning before fatal', E_USER_WARNING);
    trigger_error('isolated closure fatal', E_USER_ERROR);
})->description('Isolated failing closure');

return $schedule;
