# mydnshost-dispatcher

This repo holds the code for the Job Dispatcher for mydnshost.

Mainly as a result of certain API requests, various events are dispatched into RabbitMQ, this process watches for specific events and if required creates Jobs for the job workers (https://github.com/mydnshost/mydnshost-workers).

## Running

This is probably not useful on it's own, see https://github.com/mydnshost/mydnshost-infra

## Comments, Questions, Bugs, Feature Requests etc.

Bugs and Feature Requests should be raised on the [issue tracker on github](https://github.com/shanemcc/mydnshost-dispatcher/issues), and I'm happy to receive code pull requests via github.

I can be found idling on various different IRC Networks, but the best way to get in touch would be to message "Dataforce" on Quakenet (or chat in #Dataforce), or drop me a mail (email address is in my github profile)
