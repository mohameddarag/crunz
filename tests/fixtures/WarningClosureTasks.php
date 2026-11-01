<?php

$schedule = new \Crunz\Schedule();
$schedule->run(function () {
    trigger_error('isolated closure warning', E_USER_WARNING);
    echo 'child output';
});

return $schedule;
