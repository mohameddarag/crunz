<?php

namespace Crunz;

use Crunz\Logger\LoggerFactory;
use Crunz\Configuration\Configurable; 

class ErrorHandler extends Singleton {
    
    use Configurable;

    /**
     * Instance of the logger
     *
     * @var \Crunz\Logger
     */
    protected $logger;

    /**
     * Instance of the Mailer
     *
     * @var \Crunz\Mailer
     */
    protected $mailer;

    protected $delegateFatalErrors = false;
    protected $managedError;

    /**
     * Catch Fatal Errors
     *
     */
     public function __construct()
     {
        $this->configurable();

        $this->logger = LoggerFactory::makeOne([
            'notice' => $this->config('errors_log_file'),
        ]);

        $this->mailer = new Mailer();
     }    

    /**
     * Catch Fatal Errors
     *
     */
    public function set($delegateFatalErrors = false)
    {
        $this->delegateFatalErrors = $delegateFatalErrors;
        if ($delegateFatalErrors) {
            set_error_handler([$this, 'reportManagedError']);
        }
        ob_start([&$this, 'catchErrors']);
    }

    public function reportManagedError($type, $message, $file, $line)
    {
        if (!(error_reporting() & $type)) {
            return true;
        }

        if (in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return false;
        }

        $deprecated = in_array($type, [E_DEPRECATED, E_USER_DEPRECATED], true);
        $this->managedError = [
            'type' => $type,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ];
        fwrite(STDERR, $this->formatError($this->managedError, false, $deprecated) . PHP_EOL);

        return true;
    }

    /**
     * Determine the type of the output
     *
     * @param  string $buffer
     *
     * @return Boolean
     */
    public function catchErrors($buffer)
    {
        $error = error_get_last();
        if ($error === null && $this->delegateFatalErrors) {
            $error = $this->managedError;
        }
        if ($error !== null) {
            $fatal = in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
            if ($fatal && $this->delegateFatalErrors) {
                fwrite(STDERR, $this->formatError($error) . PHP_EOL);
                return $buffer;
            }
            error_clear_last();
            $deprecated = in_array($error['type'], [E_DEPRECATED, E_USER_DEPRECATED], true);
            $level = $fatal ? 'error' : ($deprecated ? 'notice' : 'warning');
            $message = $this->formatError($error, $fatal, $deprecated);
            $this->logger->write($message, $level);

            // Send error as email as configured
            if ($this->config('email_errors')) {
                $this->mailer->send(
                    'Crunz: reporting PHP ' . ($fatal ? 'Fatal error' : ($deprecated ? 'deprecation' : 'warning/notice')),
                    $message
                );
            }
        }

        return $buffer;       
    }

    private function formatError(array $error, $fatal = null, $deprecated = false)
    {
        if ($fatal === null) {
            $fatal = in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
        }

        $file = $this->formatErrorFile($error['file']);
        $message = str_replace($error['file'], $file, $error['message']);

        return sprintf(
            'PHP %s (type %d): %s in %s on line %d',
            $fatal ? 'fatal error' : ($deprecated ? 'deprecation' : 'warning/notice'),
            $error['type'],
            $message,
            $file,
            $error['line']
        );
    }

    private function formatErrorFile($file)
    {
        if (strpos($file, 'laravel-serializable-closure://') === 0) {
            return 'serialized-closure:' . hash('sha256', $file);
        }

        return $file;
    }

}