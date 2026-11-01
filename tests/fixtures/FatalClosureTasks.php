<?php

$schedule = new \Crunz\Schedule();
$schedule->run(function () {
    trigger_error('isolated closure fatal', E_USER_ERROR);
})->description('Isolated failing closure');

return $schedule;
