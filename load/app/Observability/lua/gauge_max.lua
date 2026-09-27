-- KEYS[1] gauge STRING key; ARGV[1] new absolute
local cur = tonumber(redis.call("GET", KEYS[1]) or "0")
local new = tonumber(ARGV[1])
if new > cur then
  redis.call("SET", KEYS[1], new)
end
return redis.call("GET", KEYS[1])
