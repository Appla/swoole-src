--TEST--
swoole_channel_coro: connection pool
--SKIPIF--
<?php require __DIR__ . '/../include/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/../include/bootstrap.php';

class RedisPool
{
    /**@var \Swoole\Coroutine\Channel */
    protected $pool;

    /**
     * RedisPool constructor.
     * @param int $size max connections
     */
    public function __construct(int $size = MAX_CONCURRENCY_LOW)
    {
        $this->pool = new \Swoole\Coroutine\Channel($size);
        for ($i = 0; $i < $size; $i++) {
            $redis = new \redis();
            $res = $redis->connect(REDIS_SERVER_HOST, REDIS_SERVER_PORT);
            if ($res == false) {
                throw new \RuntimeException("failed to connect redis server.");
            } else {
                $this->put($redis);
            }
        }
    }

    public function get(): \redis
    {
        return $this->pool->pop();
    }

    public function put(\redis $redis)
    {
        $this->pool->push($redis);
    }

    public function close(): void
    {
        $this->pool->close();
        $this->pool = null;
    }
}

$count = 0;
\Swoole\Runtime::setHookFlags(SWOOLE_HOOK_ALL);
go(function () {
    $pool = new RedisPool();
    // max concurrency num is more than max connections
    // but it's no problem, channel will help you with scheduling
    for ($c = 0; $c < MAX_CONCURRENCY_MID; $c++) {
        go(function () use ($pool, $c) {
            for ($n = 0; $n < MAX_REQUESTS; $n++) {
                $redis = $pool->get();
                if (Assert::assert($redis->set("awesome-{$c}-{$n}", 'swoole'))) {
                    if (Assert::assert($redis->get("awesome-{$c}-{$n}") === 'swoole')) {
                        if (Assert::assert($redis->delete("awesome-{$c}-{$n}"))) {
                            global $count;
                            $count++;
                        }
                    }
                }
                $pool->put($redis);
            }
        });
    }
});

Swoole\Event::wait();
Assert::same($count, MAX_CONCURRENCY_MID * MAX_REQUESTS);
?>
--EXPECT--
