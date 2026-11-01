<?php

use Crunz\Console\Command\ScheduleRunCommand;
use Crunz\ErrorHandler;
use Crunz\Event;
use Crunz\EventRunner;
use Crunz\Schedule;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class CompatibilityTest extends TestCase
{
    private $directory;
    private $environment;
    private $scanDirectory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crunz test-' . uniqid();
        mkdir($this->directory);
        $this->environment = getenv('CRUNZ_BASE_DIR');
        $this->scanDirectory = getenv('PHP_INI_SCAN_DIR');
        file_put_contents($this->directory . '/diagnostics.ini', "display_errors=stderr\n");
        putenv('PHP_INI_SCAN_DIR=' . $this->directory);
        putenv('CRUNZ_BASE_DIR=' . $this->directory);
        file_put_contents($this->directory . '/crunz.yml', "source: tasks\nlog_errors: false\nemail_errors: false\nemail_output: false\n");
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
        putenv($this->environment === false ? 'CRUNZ_BASE_DIR' : 'CRUNZ_BASE_DIR=' . $this->environment);
        putenv($this->scanDirectory === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR=' . $this->scanDirectory);
    }

    public function testCollectionAcrossFilesAndCapturedCustomArguments()
    {
        mkdir($this->directory . '/tasks with spaces');
        $fixture = file_get_contents(__DIR__ . '/fixtures/ArgumentsTasks.php');
        file_put_contents($this->directory . '/tasks with spaces/bootstrap.php', '<?php $bootstrapped = $_SERVER["argv"][3] . "|" . $_SERVER["argv"][5];');
        file_put_contents($this->directory . '/tasks with spaces/FirstTasks.php', $fixture);
        file_put_contents($this->directory . '/tasks with spaces/SecondTasks.php', $fixture);
        $previousValue = getenv('CRUNZ_TEST_VALUE');
        $previousPriority = getenv('CRUNZ_TEST_PRIORITY');
        $previousEnvironment = $_ENV;
        putenv('CRUNZ_TEST_VALUE=inherited environment');
        putenv('CRUNZ_TEST_PRIORITY=process value');
        $_ENV['CRUNZ_TEST_PRIORITY'] = 'array override';
        $previousTestNow = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 12, 20, 0));
        try {
            $tester = new CommandTester(new ScheduleRunCommand());
            ob_start();
            try {
                $status = $tester->execute([
                    'source' => $this->directory . '/tasks with spaces',
                    'db' => 'isolated database',
                    'client_url' => 'https://example.invalid/a?x=1&y=two%20words',
                ]);
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame(0, $status);
            $records = array_values(array_filter(explode(PHP_EOL, $output)));
            $this->assertCount(2, $records);
            foreach ($records as $record) {
                $this->assertSame([
                    'source' => $this->directory . '/tasks with spaces',
                    'db' => 'isolated database',
                    'client_url' => 'https://example.invalid/a?x=1&y=two%20words',
                    'environment' => 'inherited environment',
                    'environment_priority' => 'array override',
                    'expression' => '*/10 * * * * *',
                    'bootstrap' => 'isolated database|https://example.invalid/a?x=1&y=two%20words',
                ], json_decode($record, true));
            }
        } finally {
            putenv($previousValue === false ? 'CRUNZ_TEST_VALUE' : 'CRUNZ_TEST_VALUE=' . $previousValue);
            putenv($previousPriority === false ? 'CRUNZ_TEST_PRIORITY' : 'CRUNZ_TEST_PRIORITY=' . $previousPriority);
            $_ENV = $previousEnvironment;
            Carbon::setTestNow($previousTestNow);
        }
    }

    public function testSuccessfulProcessCallbacksAndWorkingDirectory()
    {
        $schedule = new Schedule();
        $sequence = [];
        $schedule->before(function () use (&$sequence) { $sequence[] = 'schedule-before'; });
        $schedule->after(function () use (&$sequence) { $sequence[] = 'schedule-after'; });
        $event = $schedule->run($this->command('success'))
            ->in($this->directory)
            ->before(function () use (&$sequence) { $sequence[] = 'event-before'; echo 'before-'; })
            ->after(function () use (&$sequence) { $sequence[] = 'event-after'; echo '-after'; });
        ob_start();
        try {
            (new EventRunner())->handle([$schedule]);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $event->getProcess()->getExitCode());
        $this->assertStringContainsString('before-' . $this->directory . '-after', $output);
        $this->assertSame([], $schedule->events());
        $this->assertSame(['schedule-before', 'event-before', 'event-after', 'schedule-after'], $sequence);
    }

    public function testFailedProcessReportsExitCodeAndBothStreamsWithoutCommandArguments()
    {
        $schedule = new Schedule();
        $event = $schedule->run($this->command('failure') . ' do-not-log-this-argument');
        $errors = [];
        $schedule->onError(function ($failed) use (&$errors) { $errors[] = $failed->getId(); });
        ob_start();
        try {
            (new EventRunner())->handle([$schedule]);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('exit code 23', $output);
        $this->assertStringContainsString('child stdout', $output);
        $this->assertStringContainsString('child stderr', $output);
        $this->assertStringNotContainsString('do-not-log-this-argument', $output);
        $this->assertSame([$event->getId()], $errors);
    }

    public function testFailedProcessWithEmptyStreamsStillReportsExitCode()
    {
        $schedule = new Schedule();
        $schedule->run($this->command('empty-failure'));
        ob_start();
        try {
            (new EventRunner())->handle([$schedule]);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('exit code 17', $output);
        $this->assertStringContainsString('STDOUT:', $output);
        $this->assertStringContainsString('STDERR:', $output);
    }

    public function testSuccessfulProcessWithEmptyStreams()
    {
        $schedule = new Schedule();
        $event = $schedule->run($this->command('empty-success'));
        ob_start();
        try {
            (new EventRunner())->handle([$schedule]);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame('', $output);
        $this->assertSame(0, $event->getProcess()->getExitCode());
    }

    public function testFatalChildIsReportedOnceThroughConfiguredLogAndEmail()
    {
        $log = $this->directory . '/errors.log';
        file_put_contents($this->directory . '/crunz.yml', "source: tasks\nlog_errors: true\nerrors_log_file: '" . str_replace("'", "''", $log) . "'\nemail_errors: false\n");
        $schedule = loadCompatibilitySchedule(__DIR__ . '/fixtures/FatalClosureTasks.php');
        $runner = new EventRunner();
        $mailer = new CompatibilityMailer();
        $this->setProperty($runner, 'mailer', $mailer);
        $this->setProperty($runner, 'config', new CompatibilityConfiguration([
            'log_errors' => true,
            'email_errors' => true,
        ]));
        ob_start();
        try {
            $runner->handle([$schedule]);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame('', $output);
        $message = file_get_contents($log);
        $this->assertSame(1, substr_count($message, 'crunz.ERROR:'));
        $this->assertStringContainsString('isolated closure fatal', $message);
        $this->assertStringContainsString('exit code 255', $message);
        $this->assertStringNotContainsString('SerializableClosure%22', $message);
        $this->assertCount(1, $mailer->messages);
        $this->assertStringContainsString('isolated closure fatal', $mailer->messages[0][1]);
        $this->assertStringContainsString('exit code 255', $mailer->messages[0][1]);
    }

    public function testLockDetectionAndCleanupForSuccessAndFailure()
    {
        foreach (['locked-success', 'locked-failure'] as $mode) {
            $event = new Event(uniqid(), $this->command($mode));
            $event->preventOverlapping();
            $event->start();
            try {
                $this->assertTrue($event->isLocked());
                $this->assertFalse($event->filtersPass());
                $event->getProcess()->wait();
                $event->releaseLock();
                $this->assertFileDoesNotExist($event->lockFile());
            } finally {
                $event->getProcess()->stop();
                if (file_exists($event->lockFile())) {
                    unlink($event->lockFile());
                }
            }
        }
    }

    public function testSuccessfulClosureRetainsNonfatalReportingAndOutput()
    {
        $log = $this->directory . '/errors.log';
        file_put_contents($this->directory . '/crunz.yml', "source: tasks\nerrors_log_file: '" . str_replace("'", "''", $log) . "'\nemail_errors: false\n");
        $schedule = loadCompatibilitySchedule(__DIR__ . '/fixtures/WarningClosureTasks.php');
        $event = $schedule->events()[0];
        ob_start();
        try {
            (new EventRunner())->handle([$schedule]);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $event->getProcess()->getExitCode());
        $this->assertSame('child output', $output);
        $message = file_get_contents($log);
        $this->assertSame(1, substr_count($message, 'crunz.WARNING:'));
        $this->assertStringContainsString('isolated closure warning', $message);
    }

    public function testRunnerReleasesFailedEventLockWithoutRunningSuccessCallbacks()
    {
        $schedule = new Schedule();
        $event = $schedule->run($this->command('locked-failure'))->preventOverlapping();
        $lockedAtFailure = false;
        $afterCalled = false;
        $event->after(function () use (&$afterCalled) { $afterCalled = true; });
        $schedule->onError(function ($failed) use (&$lockedAtFailure) {
            $lockedAtFailure = file_exists($failed->lockFile());
        });
        ob_start();
        try {
            (new EventRunner())->handle([$schedule]);
        } finally {
            ob_end_clean();
            $event->getProcess()->stop();
        }
        $this->assertTrue($lockedAtFailure);
        $this->assertFalse($afterCalled);
        $this->assertFileDoesNotExist($event->lockFile());
    }

    public function testErrorHandlerReportsActualErrorWithEmptyBufferAndPreservesOutput()
    {
        $log = $this->directory . '/errors.log';
        $process = new Process([PHP_BINARY, __DIR__ . '/fixtures/process.php', 'fatal', $log], null, getenv());
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $message = file_get_contents($log);
        $this->assertStringContainsString('isolated fatal diagnostic', $message);
        $this->assertStringContainsString('process.php on line', $message);
        $this->assertSame(1, substr_count($message, 'isolated fatal diagnostic'));
        $email = json_decode(file_get_contents($log . '.mail'), true);
        $this->assertStringContainsString('Fatal error', $email[0]);
        $this->assertStringContainsString('isolated fatal diagnostic', $email[1]);

        $handler = new ErrorHandler();
        error_clear_last();
        $this->assertSame('ordinary debug output', $handler->catchErrors('ordinary debug output'));
    }

    public function testWarningAndDeprecationAreNotReportedAsFatalOrDuplicated()
    {
        foreach (['warning', 'deprecation'] as $mode) {
            $log = $this->directory . '/' . $mode . '.log';
            $process = new Process([PHP_BINARY, __DIR__ . '/fixtures/process.php', $mode, $log], null, getenv());
            $process->run();
            $this->assertSame(0, $process->getExitCode());
            $this->assertSame('buffered output', $process->getOutput());
            $message = file_get_contents($log);
            $this->assertStringNotContainsString('Fatal', $message);
            $this->assertSame(1, substr_count($message, 'isolated ' . $mode));
            $this->assertStringNotContainsString('buffered output', $message);
            $this->assertStringContainsString($mode === 'warning' ? '.WARNING:' : '.NOTICE:', $message);
            $email = json_decode(file_get_contents($log . '.mail'), true);
            $this->assertStringNotContainsString('Fatal', $email[0]);
            $this->assertStringContainsString('isolated ' . $mode, $email[1]);
        }
    }

    public function testClosureSerializationPreservesBindingAndNestedCaptures()
    {
        $fixture = new CompatibilityClosureFixture();
        $original = $fixture->closure();
        $copy = unserialize(serialize(new \Laravel\SerializableClosure\SerializableClosure($original)));
        $this->assertSame($original(), $copy->getClosure()());
        $first = new Event(uniqid(), $original);
        $secondFixture = new CompatibilityClosureFixture();
        $second = new Event(uniqid(), $secondFixture->closure());
        $this->assertSame($first->lockFile(), $second->lockFile());
        $this->setProperty($secondFixture, 'value', 43);
        $second->setCommand($secondFixture->closure());
        $this->assertNotSame($first->lockFile(), $second->lockFile());
    }

    public function testSingletonRetainsItsLegacyUnserializationHook()
    {
        $this->assertInstanceOf(\Crunz\Singleton::class, unserialize('O:15:"Crunz\\Singleton":0:{}'));
    }

    public function testClosureLockIdentityIsStableAcrossInstancesAndCaptures()
    {
        $first = loadCompatibilitySchedule(__DIR__ . '/fixtures/LockClosureTasks.php', 'tenant-a')->events()[0];
        $second = loadCompatibilitySchedule(__DIR__ . '/fixtures/LockClosureTasks.php', 'tenant-a')->events()[0];
        $other = loadCompatibilitySchedule(__DIR__ . '/fixtures/LockClosureTasks.php', 'tenant-b')->events()[0];
        $this->assertSame($first->lockFile(), $second->lockFile());
        $this->assertNotSame($first->lockFile(), $other->lockFile());
        $first->start();
        try {
            $this->assertTrue($second->isLocked());
            $first->getProcess()->wait();
        } finally {
            $first->getProcess()->stop();
            $first->releaseLock();
        }
        $this->assertFileDoesNotExist($first->lockFile());
    }

    public function testClosureLockIdentityIsStableAcrossProcesses()
    {
        $paths = [];
        foreach ([1, 25] as $allocations) {
            $process = new Process([PHP_BINARY, __DIR__ . '/fixtures/process.php', 'closure-lock', $allocations], null, getenv());
            $process->run();
            $this->assertSame(0, $process->getExitCode());
            $paths[] = $process->getOutput();
        }
        $this->assertNotSame('', $paths[0]);
        $this->assertSame($paths[0], $paths[1]);
    }

    public function testClosureLocksIncludeUserAndWorkingDirectory()
    {
        $event = loadCompatibilitySchedule(__DIR__ . '/fixtures/LockClosureTasks.php', 'tenant')->events()[0];
        $original = $event->lockFile();
        $event->in($this->directory);
        $directoryLock = $event->lockFile();
        $this->assertNotSame($original, $directoryLock);
        $event->user('isolated-user');
        $this->assertNotSame($directoryLock, $event->lockFile());
    }

    public function testRecursiveClosureSerializationAndLockIdentity()
    {
        $closure = makeCompatibilityRecursiveClosure();
        $copy = unserialize(serialize(new \Laravel\SerializableClosure\SerializableClosure($closure)));
        $this->assertSame(5, $copy->getClosure()(5));
        $first = new Event(uniqid(), $closure);
        $second = new Event(uniqid(), makeCompatibilityRecursiveClosure());
        $this->assertSame($first->lockFile(), $second->lockFile());
    }

    public function testDefaultLogDestinationsUseThePlatformNullDevice()
    {
        $configuration = \Crunz\Configuration\Configuration::getInstance();
        $expected = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $this->assertSame($expected, $configuration->get('errors_log_file'));
        $this->assertSame($expected, $configuration->get('output_log_file'));
    }

    public function testMailerBuildsAndSendsThroughInMemoryTransport()
    {
        $transport = new \Swift_NullTransport();
        $swift = new \Swift_Mailer($transport);
        $mailer = new \Crunz\Mailer($swift);
        $this->setProperty($mailer, 'config', new CompatibilityConfiguration([
            'mailer' => [
                'sender_email' => 'scheduler@example.invalid',
                'sender_name' => 'Isolated scheduler',
                'recipients' => ['recipient@example.invalid'],
            ],
        ]));
        $mailer->send('Isolated diagnostic', 'Actual error details');
        $this->assertTrue($transport->isStarted());
    }

    private function command($mode)
    {
        return (new Process([PHP_BINARY, __DIR__ . '/fixtures/process.php', $mode]))->getCommandLine();
    }

    private function setProperty($object, $name, $value)
    {
        $property = new ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($object, $value);
    }
}

class CompatibilityConfiguration extends \Crunz\Configuration\Configuration
{
    public function __construct(array $parameters)
    {
        $this->parameters = $parameters;
    }
}

class CompatibilityMailer extends \Crunz\Mailer
{
    public $messages = [];

    public function __construct()
    {
    }

    public function send($subject, $message)
    {
        $this->messages[] = [$subject, $message];
    }
}

class CompatibilityClosureFixture
{
    private $value = 42;

    public function closure()
    {
        $nested = function ($value) { return $value + 1; };
        $capture = ['text' => 'spaces & percent % and "quotes"'];
        return function () use ($nested, $capture) {
            return [$nested($this->value), $capture];
        };
    }

}

function loadCompatibilitySchedule($path, $db = '')
{
    return require $path;
}

function makeCompatibilityRecursiveClosure()
{
    $recursive = null;
    $recursive = function ($count) use (&$recursive) {
        return $count === 0 ? 0 : 1 + $recursive($count - 1);
    };

    return $recursive;
}
