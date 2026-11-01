<?php

$nested = function ($value) {
    return $value;
};
$schedule = new \Crunz\Schedule();
$schedule->run(function () use ($db, $nested) {
    $nested($db);
    sleep(2);
})->preventOverlapping();

return $schedule;
