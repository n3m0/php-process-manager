# PHP-Process-Manager

The process manager launches the given processes by forking itself and run a pcntl_exec and restarts them if a process exits.

There is also a listener that can show a list of running processes and has the capability to shutdown the manager (and alle the child processes).

