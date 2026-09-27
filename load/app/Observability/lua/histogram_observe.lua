-- KEYS[1] = series HASH
-- ARGV[1] = observation (finite number)
-- ARGV[2..] = ordered bucket labels, last MUST be +Inf
local key = KEYS[1]
local value = tonumber(ARGV[1])
if not value or value ~= value or value == math.huge or value == -math.huge then
  return redis.error_reply("ERR observation must be finite")
end
if value < 0 then
  return redis.error_reply("ERR observation must be >= 0")
end
local matched = 0
for i = 2, #ARGV do
  local bucket = ARGV[i]
  if bucket == "+Inf" or value <= tonumber(bucket) then
    redis.call("HINCRBY", key, "le:" .. bucket, 1)
    matched = matched + 1
  end
end
if matched == 0 then
  return redis.error_reply("ERR no matching bucket; include +Inf")
end
redis.call("HINCRBYFLOAT", key, "_sum", value)
local count = redis.call("HINCRBY", key, "_count", 1)
local inf = redis.call("HGET", key, "le:+Inf")
if tostring(inf) ~= tostring(count) then
  return redis.error_reply("ERR +Inf ~= _count")
end
return count
