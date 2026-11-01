<?php

namespace Crunz\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Crunz\Invoker;
use Crunz\Configuration\Configurable;
use Crunz\Exception\CrunzException;
use Laravel\SerializableClosure\SerializableClosure;

class ClosureRunCommand extends Command
{
    /**
     * Configures the current command
     *
     */
    protected function configure()
    {    
       $this->setName('closure:run')
            ->setDescription('Executes a closure as a process.')
            ->setDefinition([
               new InputArgument('closure', InputArgument::REQUIRED, 'The closure to run'), 
            ])
            ->setHelp('This command executes a closure as a separate process.');
    } 

    /**
     * Executes the current command
     *
     * @param use Symfony\Component\Console\Input\InputInterface $input
     * @param use Symfony\Component\Console\Input\OutputIterface $output
     *
     * @return null|int null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {              
        $args = [];
        $this->arguments = $input->getArguments();
        
        parse_str($this->arguments['closure'], $args);
        if (!isset($args[0]) || !is_string($args[0]) || $args[0] === '') {
            throw new \InvalidArgumentException('Missing serialized closure.');
        }
        $closure = unserialize($args[0]);
        if (!$closure instanceof SerializableClosure) {
            throw new \UnexpectedValueException('The payload does not contain a closure.');
        }
        call_user_func_array($closure->getClosure(), []);
    }  

}