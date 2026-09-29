#!/bin/sh
# P6.1 Redis depth sampler. Pending is LLEN of the list key. Delayed and
# reserved are ZCARD. RedisQueue::size() is the sum of those planes and is
# never a pending sample. A missed probe stays missing; it is not stored as 0.
set -eu

usage() {
    echo "usage: $0 syntax|sample" >&2
    exit 1
}

[ $# -eq 1 ] || usage

pending_key() {
    printf 'queues:%s' "$1"
}

case "$1" in
    syntax)
        printf 'queue=load-health pending=LLEN %s delayed=ZCARD %s:delayed reserved=ZCARD %s:reserved\n' \
            "$(pending_key load-health)" "$(pending_key load-health)" "$(pending_key load-health)"
        printf 'queue=default pending=LLEN %s delayed=ZCARD %s:delayed reserved=ZCARD %s:reserved\n' \
            "$(pending_key default)" "$(pending_key default)" "$(pending_key default)"
        echo "p6-queue-snapshot syntax: ok size=forbidden"
        ;;
    sample)
        if [ "${P6_REDIS_LIVE:-0}" != 1 ]; then
            echo "p6-queue-snapshot sample: missing redis sample (P6_REDIS_LIVE=1 required; not recorded as zero)" >&2
            exit 2
        fi
        host=${REDIS_HOST:-127.0.0.1}
        port=${REDIS_PORT:-6379}
        for queue in load-health default; do
            key=$(pending_key "$queue")
            pending=$(redis-cli -h "$host" -p "$port" LLEN "$key")
            delayed=$(redis-cli -h "$host" -p "$port" ZCARD "${key}:delayed")
            reserved=$(redis-cli -h "$host" -p "$port" ZCARD "${key}:reserved")
            printf 'queue=%s pending=%s delayed=%s reserved=%s\n' "$queue" "$pending" "$delayed" "$reserved"
        done
        ;;
    *)
        usage
        ;;
esac
