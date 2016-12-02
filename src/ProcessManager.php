<?php

declare(ticks = 1);

class ProcessManager
{
    /**
     * @var Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $children = array();

    /**
     * @var bool
     */
    protected $shutdown = false;

    /**
     * @var resource
     */
    protected $socket;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @return ProcessManager
     */
    public function __construct($port, \Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;

        pcntl_signal(SIGINT, array($this, "signalHandler"));
        pcntl_signal(SIGHUP, array($this, "signalHandler"));

        $this->socket = $this->createCommandSocket($port);
    }

    /**
     * @param array $commands
     * @return void
     */
    public function run(array $commands)
    {

        foreach ($commands as $command) {
            $this->logger->info(sprintf('launching %s', $command));
            $this->forkAndRun($command);
        }

        while ($this->shutdown === false || count($this->children) > 0) {

            $this->watchAndRestartChildren();

            sleep(2);

            if (($msgsock = socket_accept($this->socket)) !== false) {
                $this->readFromSocket($msgsock);
            }
        }

        posix_kill(posix_getpid(), SIGINT);
    }

    /**
     * @return void
     */
    protected function watchAndRestartChildren()
    {
        foreach ($this->children as $pid => $command) {
            $status = false;
            $res = pcntl_waitpid($pid, $status, WNOHANG);

            if ($res == -1 || $res > 0) {
                $this->logger->info(sprintf("%s has exited with state %s", $command, $status));
                unset($this->children[$pid]);

                if ($this->shutdown === false) {
                    $this->logger->info(sprintf("restarting %s", $command));
                    $this->forkAndRun($command);
                }
            }
        };
    }

    /**
     * @param $signal
     * @param int $pid
     * @param int $status
     * @return void
     */
    public function signalHandler($signal, $pid = null, $status = null)
    {
        $this->logger->info(sprintf('signal received: %s', $signal));

        $this->shutdown = true;

        foreach ($this->children as $pid => $command) {
            $this->logger->info(sprintf('stopping %s', $command));
            $this->kill($pid, $signal);
        };
    }

    /**
     * @param int $pid
     * @param int $signal
     * @return void
     */
    protected function kill($pid, $signal)
    {
        $this->logger->info(sprintf('sending signal %s to %s', $signal, $pid));
        posix_kill($pid, $signal);
    }

    protected function stop($command)
    {
        foreach ($this->children as $pid => $cmd) {
            if ($cmd == $command) {
                $this->logger->info(sprintf('stopping %s', $command));
                $this->kill($pid, SIGHUP);
            }
        };
    }

    /**
     * @param string $command
     * @return void
     */
    protected function forkAndRun($command)
    {
        $pid = pcntl_fork();

        if ($pid) {
            $this->children[$pid] = $command;
        } else {
            $params = explode(' ', $command);
            $binary = array_shift($params);

            $this->logger->info(sprintf('starting %s in process with id %s', $command, posix_getpid()));
            pcntl_exec($binary, $params);
            exit; //exit if exec fails
        }
    }

    /**
     * @return resource
     */
    protected function createCommandSocket($port)
    {
        $address = 'localhost';

        if (($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            $this->logger->error("socket_create() fehlgeschlagen: Grund: " . socket_strerror(socket_last_error()));
            exit;
        }

        if (socket_bind($socket, $address, $port) === false) {
            $this->logger->error("socket_bind() fehlgeschlagen: Grund: " . socket_strerror(socket_last_error($socket)));
            exit;
        }

        if (socket_listen($socket, 5) === false) {
            $this->logger->error("socket_listen() fehlgeschlagen: Grund: " . socket_strerror(socket_last_error($socket)));
            exit;
        }

        socket_set_nonblock($socket);
        return $socket;

    }

    /**
     * @param resource $msgsock
     * @return void
     */
    protected function readFromSocket($msgsock)
    {
        $msg = "OK\n";
        socket_write($msgsock, $msg, strlen($msg));

        do {
            if (false === ($buffer = socket_read($msgsock, 2048, PHP_NORMAL_READ))) {
                $this->logger->error("socket_read() fehlgeschlagen: Grund: " . socket_strerror(socket_last_error($msgsock)));
                break;
            }

            $buffer = trim($buffer, "\n\r ");

            switch ($buffer) {
                default:
                    $message = $buffer . "\n";
                    socket_write($msgsock, $message, strlen($message));
                    break;
                case 'shutdown':
                    $this->signalHandler(SIGHUP);

                    while (count($this->children) > 0) {
                        echo '.';

                        foreach ($this->children as $pid => $command) {
                            $res = pcntl_waitpid($pid, $status, WNOHANG);
                            if ($res == -1 || $res > 0) {
                                $this->logger->info(sprintf("%s has exited with state %s", $command, $status));
                                unset($this->children[$pid]);
                            }
                        }

                        sleep(1);
                    }

                    socket_close($msgsock);
                    return;
                    break;
                case 'exit':
                    socket_close($msgsock);
                    return;
                    break;
                case 'list':
                    foreach ($this->children as $pid => $command) {
                        $talkback = $command . ' => ' . $pid . "\n";
                        socket_write($msgsock, $talkback, strlen($talkback));
                    }
                    break;
            }
        } while (true);
    }
}