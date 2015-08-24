<?php

namespace Footstones\Plumber;

use Footstones\Plumber\BeanstalkClient;
use swoole_table;
use swoole_buffer;

class ListenerStats
{
    private $table;

    private $pids;

    public function __construct($size)
    {
        $table = new swoole_table($this->calTableSize($size));
        $table->column('count', swoole_table::TYPE_INT);
        $table->column('last_update', swoole_table::TYPE_INT);
        $table->column('job_id', swoole_table::TYPE_INT);
        $table->column('timeout', swoole_table::TYPE_INT);
        $table->create();
        $this->table = $table;

        $pids = new swoole_table(1);
        $pids->column('pids', swoole_table::TYPE_STRING, $size*10);
        $pids->create();
        $this->pids = $pids;
    }

    /**
     * 更新状态
     */
    public function touch($tube, $pid, $incr = false, $jobId = 0)
    {
        $key = $this->getKey($pid);

        $this->table->lock();
        $stats = $this->table->get($key);
        if ($stats === false) {
            $this->table->set($key, array(
                'count' => $incr ? 1 : 0,
                'last_update' => time(),
                'tube' => $tube,
                'job_id' => $jobId,
                'timeout' => 0,
            ));

            $pids = $this->pids->get('data') ? : array('pids' => '');
            $this->pids->set('data', array('pids' => $pids['pids']. $pid. ' '));
        } else {
            $this->table->set($key, array(
                'count' => $incr ? ($stats['count'] + 1) : $stats['count'],
                'last_update' => time(),
                'tube' => $tube,
                'job_id' => $jobId,
                'timeout' => 0,
            ));
        }
        $this->table->unlock();
    }

    public function timeout($pid)
    {
        $this->table->incr($this->getKey($pid), 'timeout');
    }

    public function get($pid)
    {
        $key = $this->getKey($pid);
        $stats = $this->table->get($key);
        if ($stats === false) {
            return array('count' => 0, 'last_update' => 0, 'tube' => '', 'job_id' => 0, 'timeout' => 0);
        }
        return $stats;

    }

    public function getAll()
    {
        $pids = $this->pids->get('data') ? : array('pids' => '');
        $pids = trim($pids['pids']);
        $pids = empty($pids) ? array() : explode(' ', $pids);

        $statses = array();
        foreach ($pids as $pid) {
            $statses[$pid] = $this->get($pid);
        }
        return $statses;
    }

    private function getKey($pid)
    {
        return 'p_' . $pid;
    }

    private function calTableSize($size)
    {
        for($i=1; $i<=100; $i++) {
            $tableSize = pow(2, $i);
            if ($tableSize >= $size) {
                return $tableSize;
            }
        }

        throw new \RuntimeException("");
    }

}