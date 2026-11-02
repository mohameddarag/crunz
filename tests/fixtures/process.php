<?php

$mode = $argv[1];
if ($mode === 'success') {
    echo getcwd();
    exit(0);
}
if ($mode === 'failure') {
    echo 'child stdout';
    fwrite(STDERR, 'child stderr');
    exit(23);
}
if ($mode === 'empty-success' || $mode === 'empty-failure') {
    exit($mode === 'empty-success' ? 0 : 17);
}
if ($mode === 'locked-success' || $mode === 'locked-failure') {
    sleep(2);
    exit($mode === 'locked-success' ? 0 : 19);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if ($mode === 'repeat-warning') {
    $handler = new \Crunz\ErrorHandler();
    $logger = new class {
        public $messages = [];

        public function write($message, $level)
        {
            $this->messages[] = [$message, $level];
        }
    };
    $property = new ReflectionProperty($handler, 'logger');
    $property->setValue($handler, $logger);
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    for ($i = 0; $i < 2; ++$i) {
        trigger_error('repeated isolated warning', E_USER_WARNING);
        $handler->catchErrors('');
    }
    echo count($logger->messages);
    exit(0);
}
if ($mode === 'closure-lock') {
    $objects = [];
    for ($i = 0; $i < $argv[2]; ++$i) {
        $objects[] = new \stdClass();
    }
    $db = 'tenant-a';
    $schedule = require __DIR__ . '/LockClosureTasks.php';
    echo $schedule->events()[0]->lockFile();
    exit(0);
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$handler = new \Crunz\ErrorHandler();
$logger = new \Crunz\Logger\Logger(new \Monolog\Logger('crunz-test'));
$logger->addStream($argv[2], 'notice');
$property = new ReflectionProperty($handler, 'logger');
if (PHP_VERSION_ID < 80100) {
    $property->setAccessible(true);
}
$property->setValue($handler, $logger);
$mailer = new class($argv[2] . '.mail') extends \Crunz\Mailer {
    private $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function send($subject, $message)
    {
        file_put_contents($this->path, json_encode([$subject, $message]));
    }
};
foreach ([
    'mailer' => $mailer,
    'config' => new class {
        public function get($key)
        {
            return $key === 'email_errors';
        }
    },
] as $name => $value) {
    $property = new ReflectionProperty($handler, $name);
    if (PHP_VERSION_ID < 80100) {
        $property->setAccessible(true);
    }
    $property->setValue($handler, $value);
}
error_clear_last();
$handler->set();
if ($mode === 'fatal') {
    trigger_error('isolated fatal diagnostic', E_USER_ERROR);
}
echo 'buffered output';
trigger_error('isolated ' . $mode, $mode === 'warning' ? E_USER_WARNING : E_USER_DEPRECATED);
$handler->catchErrors('');
$handler->catchErrors('');
