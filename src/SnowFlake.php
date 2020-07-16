<?php
declare(strict_types=1);

namespace Rabbit\SnowFlake;

use Rabbit\Base\atomic\AtomicLock;
use Rabbit\Base\Contract\IdInterface;
use Rabbit\Base\Contract\LockInterface;
use Swoole\Atomic;
use Throwable;

/**
 * Class SnowFlake
 * @package Rabbit\SnowFlake
 */
class SnowFlake implements IdInterface
{
    //开始时间截 (2018-01-01)
    const twepoch = 1514736000000;

    //机器id所占的位数
    const workerIdBits = 10;

    //支持的最大机器id，结果是1023-3 (这个移位算法可以很快的计算出几位二进制数所能表示的最大十进制数)
    const maxWorkerId = (-1 ^ (-1 << self::workerIdBits)) - (-1 ^ (-1 << 2));

    //序列在id中占的位数
    const sequenceBits = 12;

    //机器ID向左移12位
    const workerIdShift = self::sequenceBits;

    //时间截向左移22位(10+12)
    const timestampLeftShift = self::workerIdBits + self::sequenceBits;

    //序列号值的最大值，这里为4095 (0b111111111111=0xfff=4095)
    const sequenceMask = (-1 ^ (-1 << self::sequenceBits));

    //工作ID(0~1020)：默认0，预留2位给ID类型
    private int $workerId;

    private ?Atomic $atomic = null;
    /** @var int */
    private int $lastTimestamp = self::twepoch;
    private ?LockInterface $lock = null;
    /** @var bool */
    private bool $useExt = false;

    /**
     * SnowFlake constructor.
     * @param int $workerId
     */
    public function __construct(int $workerId = 0)
    {
        $this->workerId = $workerId;
        if ($this->workerId > self::maxWorkerId) {
            $this->workerId = rand(0, self::maxWorkerId);
        }
        if (extension_loaded('donkeyid')) {
            $this->useExt = true;
            ini_set('node_id', (string)$this->workerId);
            ini_set('epoch', (string)self::twepoch);
        } else {
            $this->atomic = new Atomic();
            $this->lock = new AtomicLock();
        }
    }

    /**
     * @return int
     * @throws Throwable
     */
    private function nextId(): int
    {
        $lock = $this->lock;
        return (int)$lock(function () {
            //获取上一次生成id时的毫秒时间戳，需要跨进程共享属性
            $lastTimestamp = $this->lastTimestamp;
            //获取当前毫秒时间戳
            $time = microtime(true) * 1000;
            /**
             * 高并发下，多进程模式会出现当前时间小于上一次ID生成的时间戳，不一定是时钟回退。工作ID加入进程ID即可解决，但是进程ID不好预留
             * 暂时先立即更新最后一次生成的时间戳
             */
            if ($time < $lastTimestamp) {
                throw new \RuntimeException("Clock moved backwards. Refusing to generate id for lastTimestamp {$lastTimestamp} milliseconds");
            }

            //如果是同一毫秒内生成的，则进行毫秒序列化
            if ($lastTimestamp === $time) {
                //获取当前序列号值，原子计数器自增 毫秒序列化值溢出（就是超过了4095）
                if ($this->atomic->add() & self::sequenceMask === 0) {
                    $this->atomic->set(0);
                    //获得新的时间戳
                    $time = $this->tilNextMillis($lastTimestamp);
                }
            } else {
                //如果不是同一毫秒，那么重置毫秒序列化值
                $this->atomic->set(0);
            }

            //重置最后一次生成的时间戳
            $this->lastTimestamp = $time;

            if ($this->workerId > -1) {
                return
                    //时间戳左移 22 位
                    (($time - self::twepoch) << self::timestampLeftShift) |
                    //机器id左移 12 位
                    ($this->workerId << self::workerIdShift) |
                    //或运算序列号值
                    $this->atomic->get();
            } else {
                return
                    //时间戳左移 10 位
                    (($time - self::twepoch) << 10) |
                    //或运算序列号值
                    $this->atomic->get();
            }
        });
    }

    /**
     * @param float $lastTimestamp
     * @return int
     */
    private function tilNextMillis(float $lastTimestamp): int
    {
        $time = microtime(true) * 1000;
        while ($time <= $lastTimestamp) {
            $time = microtime(true) * 1000;
        }
        return $time;
    }

    /**
     * @return int|mixed
     * @throws Throwable
     */
    public function create()
    {
        if ($this->useExt) {
            return (int)dk_get_next_id();
        }
        return (int)$this->nextId();
    }
}
