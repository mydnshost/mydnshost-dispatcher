FROM mydnshost/mydnshost-api AS api

FROM mydnshost/mydnshost-api-docker-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY --from=api /dnsapi /dnsapi

COPY . /dnsapi/dispatcher

ENTRYPOINT ["/dnsapi/dispatcher/JobDispatcher.php"]
