<?php

/*
 * This file is part of the AllProgrammic Resque package.
 *
 * (c) AllProgrammic SAS <contact@allprogrammic.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace AllProgrammic\Component\Resque;

use Psr\Log\LoggerInterface;
use AllProgrammic\Component\Resque\Events\QueueEvent;
use AllProgrammic\Component\Resque\Failure\FailureInterface;
use AllProgrammic\Component\Resque\Job\DontCreate;
use AllProgrammic\Component\Resque\Job\Status;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Engine
{
    /** @var Redis */
    private $backend;

    /** @var EventDispatcherInterface */
    private $dispatcher;

    /** @var ContainerInterface */
    private $container;

    /** @var FailureInterface */
    private $failureHandler;
    
    /** @var Stat */
    private $stat;

    /** @var Status */
    private $statusManager;

    /** @var Supervisor */
    private $supervisor;

    public function __construct(
        Redis $backend,
        EventDispatcherInterface $dispatcher,
        ContainerInterface $container,
        Stat $stat,
        Status $statusManager,
        FailureInterface $failureHandler,
        LoggerInterface $logger = null
    ) {
        $this->backend = $backend;
        $this->container = $container;
        $this->dispatcher = $dispatcher;
        $this->stat = $stat;
        $this->statusManager = $statusManager;
        $this->failureHandler = $failureHandler;
        $this->supervisor = new Supervisor($this, $this->backend, $dispatcher, $failureHandler, $logger);
    }

    /**
     * @return FailureInterface
     */
    public function getFailure()
    {
        return $this->failureHandler;
    }

    /**
     * fork() helper method for php-resque that handles issues PHP socket
     * and phpredis have with passing around sockets between child/parent
     * processes.
     *
     * Will close connection to Redis before forking.
     *
     * @return int Return vars as per pcntl_fork()
     */
    public function fork()
    {
        if (!function_exists('pcntl_fork')) {
            return -1;
        }

        // Close the connection to Redis before forking.
        // This is a workaround for issues phpredis has.
        $this->backend->forceClose();

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child worker.');
        }

        return $pid;
    }

    /**
     * Push a job to the end of a specific queue. If the queue does not
     * exist, then create it as well.
     *
     * @param string $queue The name of the queue to add the job to.
     * @param array $item Job description as an array to be JSON encoded.
     *
     * @return bool
     */
    public function push($queue, $item)
    {
        $encodedItem = json_encode($item);

        if ($encodedItem === false) {
            return false;
        }

        $this->backend->sAdd('queues', $queue);
        $length = $this->backend->rPush('queue:' . $queue, $encodedItem);

        if ($length < 1) {
            return false;
        }
        return true;
    }

    /**
     * Pop an item off the end of the specified queue, decode it and
     * return it.
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @return array Decoded item from the queue.
     */
    public function pop($queue)
    {
        $item =  $this->backend->lPop('queue:' . $queue);

        if (!$item) {
            return null;
        }

        return json_decode($item, true);
    }

    /**
     * Remove items of the specified queue
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @param array $items
     * @return integer number of deleted items
     */
    public function dequeue($queue, $items = array())
    {
        if (count($items) > 0) {
            return $this->removeItems($queue, $items);
        }

        return $this->removeList($queue);
    }

    /**
     * Remove specified queue
     *
     * @param string $queue The name of the queue to remove.
     * @return integer Number of deleted items
     */
    public function removeQueue($queue)
    {
        $num = $this->removeList($queue);

        $this->backend->sRem('queues', $queue);

        return $num;
    }

    /**
     * Pop an item off the end of the specified queues, using blocking list pop,
     * decode it and return it.
     *
     * @param array         $queues
     * @param int           $timeout
     * @return null|array   Decoded item from the queue.
     */
    public function blpop(array $queues, $timeout)
    {
        $list = array();
        foreach ($queues as $queue) {
            $list[] = 'queue:' . $queue;
        }

        $item = $this->backend->blPop($list, (int)$timeout);

        if (!$item) {
            return null;
        }

        /**
         * Normally the Redis class returns queue names without the prefix
         * But the blpop is a bit different. It returns the name as prefix:queue:name
         * So we need to strip off the prefix:queue: part
         */
        $queue = substr($item[0], strlen($this->backend->getNamespace() . 'queue:'));

        return [
            'queue'   => $queue,
            'payload' => json_decode($item[1], true),
        ];
    }

    /**
     * Return the size (number of pending jobs) of the specified queue.
     *
     * @param string $queue name of the queue to be checked for pending jobs
     *
     * @return int The size of the queue.
     */
    public function size($queue)
    {
        return $this->backend->lLen(sprintf('queue:%s', $queue));
    }

    public function peekInQueue($queue, $start = 0, $count = 1)
    {
        if (1 === $count) {
            $data = json_decode($this->backend->lIndex(sprintf('queue:%s', $queue), $start), true);
            $data['queue_time'] = date_timestamp_set(date_create(), $data['queue_time']);

            return [$data];
        }

        return array_map(function ($value) {
            $data = json_decode($value, true);
            $data['queue_time'] = date_timestamp_set(date_create(), $data['queue_time']);

            return $data;
        }, $this->backend->lRange(sprintf('queue:%s', $queue), $start, $start + $count - 1));
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param boolean $trackStatus Set to true to be able to monitor the status of a job.
     *
     * @return string|boolean Job ID when the job was created, false if creation was cancelled due to beforeEnqueue
     */
    public function enqueue($queue, $class, $args = null, $trackStatus = false)
    {
        $id = $this->generateJobId();

        try {
            $this->dispatcher->dispatch(ResqueEvents::BEFORE_ENQUEUE, new QueueEvent($class, $args, $queue, $id));
        } catch (DontCreate $e) {
            return false;
        }

        $id = $this->createJob($queue, $class, $args, $trackStatus);

        $this->dispatcher->dispatch(ResqueEvents::AFTER_ENQUEUE, new QueueEvent($class, $args, $queue, $id));

        return $id;
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param boolean $monitor Set to true to be able to monitor the status of a job.
     * @param string $id Unique identifier for tracking the job. Generated if not supplied.
     *
     * @return string
     */
    private function createJob($queue, $class, $args = null, $monitor = false)
    {
        $id = self::generateJobId();

        if ($args !== null && !is_array($args)) {
            throw new \InvalidArgumentException(
                'Supplied $args must be an array.'
            );
        }

        $this->push($queue, [
            'class'     => $class,
            'args'  => array($args),
            'id'    => $id,
            'queue_time' => microtime(true),
        ]);

        if ($monitor) {
            $this->statusManager->create($id);
        }

        return $id;
    }

    /**
     * Re-queue the current job.
     * @return string
     */
    public function recreateJob(Job $job)
    {
        return $this->createJob(
            $job->queue,
            $job->payload['class'],
            $job->getArguments(),
            $this->statusManager->isTracking($job->getId())
        );
    }

    /**
     * Return the status of a job.
     *
     * @return int The status of the job as one of the Status constants.
     */
    public function getJobStatus($job)
    {
        if ($job instanceof Job) {
            $job = $job->getId();
        }

        return $this->statusManager->get($job);
    }

    /**
     * @return Redis
     */
    public function getBackend(): Redis
    {
        return $this->backend;
    }

    public function getSupervisor()
    {
        return $this->supervisor;
    }

    /**
     * Look for any workers which should be running on this server and if
     * they're not, remove them from Redis.
     */
    public function pruneDeadWorkers()
    {
        $this->supervisor->pruneDeadWorkers();
    }

    /**
     * Reserve and return the next available job in the specified queue.
     *
     * @param string $queue Queue to fetch next available job from.
     * @return Job|bool Instance of Resque_Job to be processed, false if none or error.
     */
    public function reserve($queue)
    {
        $payload = $this->pop($queue);

        if (!is_array($payload)) {
            return false;
        }

        return new Job($queue, $payload);
    }

    /**
     * Find the next available job from the specified queues using blocking list pop
     * and return an instance of Resque_Job for it.
     *
     * @param array             $queues
     * @param int               $timeout
     * @return bool|null|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
     */
    public function reserveBlocking(array $queues, $timeout = null)
    {
        $item = $this->blpop($queues, $timeout);

        if (!is_array($item)) {
            return false;
        }

        return new Job($item['queue'], $item['payload']);
    }

    /**
     * Register this worker in Redis.
     *
     * @param Worker $worker
     */
    public function registerWorker(Worker $worker)
    {
        $this->backend->sAdd('workers', (string)$worker);
        $this->backend->set(sprintf('worker:%s:started', (string)$worker), strftime('%a %b %d %H:%M:%S %Z %Y'));
    }

    /**
     * Unregister this worker in Redis. (shutdown etc)
     *
     * @param Worker $worker
     */
    public function unregisterWorker(Worker $worker)
    {
        $worker->unregister();

        $id = (string)$worker;
        $this->backend->sRem('workers', $id);
        $this->backend->del(sprintf('worker:%s', $id));
        $this->backend->del(sprintf('worker:%s:started', $id));

        $this->stat->clear(sprintf('processed:%s', $id));
        $this->stat->clear(sprintf('failed:%s', $id));
    }

    /**
     * @param Worker $worker
     * @param Job $job
     */
    public function updateWorkerJob(Worker $worker, Job $job = null)
    {
        if (!$job) {
            $this->backend->del('worker:' . (string)$worker);

            $this->stat->incr('processed');
            $this->stat->incr('processed:' . (string)$worker);

            return;
        }

        $this->backend->set(sprintf('worker:%s', $worker), json_encode([
            'queue' => $job->queue,
            'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
            'payload' => $job->payload,
        ]));
    }

    /**
     * Get an array of all known queues.
     *
     * @return array Array of queues.
     */
    public function queues()
    {
        $queues = $this->backend->sMembers('queues');

        if (!is_array($queues)) {
            $queues = [];
        }

        return $queues;
    }

    /**
     * Remove Items from the queue
     * Safely moving each item to a temporary queue before processing it
     * If the Job matches, counts otherwise puts it in a requeue_queue
     * which at the end eventually be copied back into the original queue
     *
     * @private
     *
     * @param string $queue The name of the queue
     * @param array $items
     * @return integer number of deleted items
     */
    private function removeItems($queue, $items = array())
    {
        $counter = 0;
        $originalQueue = 'queue:'. $queue;
        $tempQueue = $originalQueue. ':temp:'. time();
        $requeueQueue = $tempQueue. ':requeue';

        // move each item from original queue to temp queue and process it
        $finished = false;
        while (!$finished) {
            $string = $this->backend->rPoplPush($originalQueue, $this->backend->getNamespace() . $tempQueue);

            if (!empty($string)) {
                if (self::matchItem($string, $items)) {
                    $this->backend->rPop($tempQueue);
                    $counter++;
                } else {
                    $this->backend->rPoplPush($tempQueue, $this->backend->getNamespace() . $requeueQueue);
                }
            } else {
                $finished = true;
            }
        }

        // move back from temp queue to original queue
        $finished = false;
        while (!$finished) {
            $string =  $this->backend->rPoplPush($requeueQueue, $this->backend->getNamespace() .$originalQueue);
            if (empty($string)) {
                $finished = true;
            }
        }

        // remove temp queue and requeue queue
        $this->backend->del($requeueQueue);
        $this->backend->del($tempQueue);

        return $counter;
    }

    public function statFailed($worker)
    {
        $this->stat->incr('failed');
        $this->stat->incr(sprintf('failed:%s', $worker));
    }

    /**
     * Remove List
     *
     * @param string $queue the name of the queue
     *
     * @return int number of deleted items belongs to this list
     */
    private function removeList($queue)
    {
        $counter = self::size($queue);
        $result = $this->backend->del('queue:' . $queue);
        return ($result == 1) ? $counter : 0;
    }

    /*
     * Generate an identifier to attach to a job for status tracking.
     *
     * @return string
     */
    public static function generateJobId()
    {
        return md5(uniqid('', true));
    }

    /**
     * matching item
     * item can be ['class'] or ['class' => 'id'] or ['class' => {:foo => 1, :bar => 2}]
     * @private
     *
     * @param string $string redis result in json
     * @param $items
     *
     * @return bool
     */
    private static function matchItem($string, $items)
    {
        $decoded = json_decode($string, true);

        foreach ($items as $key => $val) {
            # class name only  ex: item[0] = ['class']
            if (is_numeric($key)) {
                if ($decoded['class'] == $val) {
                    return true;
                }
                # class name with args , example: item[0] = ['class' => {'foo' => 1, 'bar' => 2}]
            } elseif (is_array($val)) {
                $decodedArgs = (array)$decoded['args'][0];
                if ($decoded['class'] == $key &&
                    count($decodedArgs) > 0 && count(array_diff($decodedArgs, $val)) == 0) {
                    return true;
                }
                # class name with ID, example: item[0] = ['class' => 'id']
            } else {
                if ($decoded['class'] == $key && $decoded['id'] == $val) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public static function getHostname()
    {
        if (function_exists('gethostname')) {
            return gethostname();
        }

        return php_uname('n');
    }

    public function updateJobStatus($id, $status)
    {
        $this->statusManager->update($id, $status);
    }

    public function getService($id)
    {
        return $this->container->get($id, ContainerInterface::NULL_ON_INVALID_REFERENCE);
    }
}