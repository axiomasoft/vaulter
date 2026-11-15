#!/bin/sh
# collectors. Record object key/checksum/size, DB entity/status/checksum,
# temp free bytes + write length, /up+/ready, and a Prometheus pointer.
# Never record object body or credentials. A missed probe stays missing.
set -eu

usage() {
    echo "usage: $0 syntax|sample" >&2
    exit 1
}

[ $# -eq 1 ] || usage

case "$1" in
    syntax)
        echo "collector=database entity=upload_session,upload_part,blob,file_version,outbox fields=id,status,key,checksum,size body=forbidden payload=forbidden"
        echo "collector=minio fields=key,checksum,size body=forbidden credentials=forbidden"
        echo "collector=temp fields=path,free_bytes,write_length scope=run-tmpfs wildcard=forbidden"
        echo "collector=readiness up=/up ready=/ready recovered=not-from-probe-alone"
        echo "collector=prometheus path=prometheus.prom availability,http_outcome,durable_state=separate"
        echo "p7-chaos-snapshot syntax: ok cleanup=run-scope checksum+size body=forbidden"
        ;;
    sample)
        if [ "${P7_LIVE:-0}" != 1 ]; then
            echo "p7-chaos-snapshot sample: missing sample (P7_LIVE=1 required; not recorded as zero)" >&2
            exit 2
        fi
        echo "p7-chaos-snapshot sample: live collectors belong to P7.3; P7.1 records schema only" >&2
        exit 2
        ;;
    *)
        usage
        ;;
esac
