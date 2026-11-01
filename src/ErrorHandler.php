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

    protected $reportedError;
    protected $delegateFatalErrors = false;

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
        ob_start([&$this, 'catchErrors']);
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
        if ($error !== null && $error !== $this->reportedError) {
            $this->reportedError = $error;
            $fatal = in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
            if ($fatal && $this->delegateFatalErrors) {
                return $buffer;
            }
            $deprecated = in_array($error['type'], [E_DEPRECATED, E_USER_DEPRECATED], true);
            $level = $fatal ? 'error' : ($deprecated ? 'notice' : 'warning');
            $message = sprintf(
                'PHP %s (type %d): %s in %s on line %d',
                $fatal ? 'fatal error' : ($deprecated ? 'deprecation' : 'warning/notice'),
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
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

}