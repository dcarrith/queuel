<?php namespace Dcarrith\Queuel\Jobs;

use Dcarrith\Queuel\IronQueue;
use Illuminate\Container\Container;
//use Log;

class IronJob extends QueuelJob {

	/**
	 * The Iron queue instance.
	 *
	 * @var \Dcarrith\Queuel\IronQueue
	 */
	protected $iron;

	/**
	 * The IronMQ message instance.
	 *
	 * @var array
	 */
	protected $job;

	/**
	 * Indicates if the message was a push message.
	 *
	 * @var bool
	 */
	protected $pushed = false;

	/**
	 * Create a new job instance.
	 *
	 * @param  \Illuminate\Container\Container  $container
	 * @param  \Dcarrith\Queuel\IronQueue  $iron
	 * @param  object  $job
	 * @param  bool    $pushed
	 * @return void
	 */
	public function __construct(Container $container,
                                IronQueue $iron,
                                $job,
                                $pushed = false)
	{

		//Log::info('IronJob __construct > job:', (array)$job);

		$this->job = $job;
		$this->iron = $iron;
		$this->pushed = $pushed;
		$this->container = $container;
	}

	/**
	 * Fire the job.
	 *
	 * @return void
	 */
	public function fire()
	{
		//Log::info('IronJob fire > json_decode(this->getRawBody):', array(json_decode($this->getRawBody(), true)));

		$this->resolveAndFire(json_decode($this->getRawBody(), true));
	}

	/**
	 * Get the raw body string for the job.
	 *
	 * @return string
	 */
	public function getRawBody()
	{
		//Log::info('IronJob getRawBody > this->job->body:', array($this->job->body));

		return $this->job->body;
	}

	/**
	 * Delete the job from the queue.
	 *
	 * @return void
	 */
	public function delete()
	{
		//Log::info('IronJob delete', array('message' => 'calling parent::delete()'));

		parent::delete();

		//Log::info('IronJob delete', array('message' => 'checking if isset(this->job->pushed)'));

		if (isset($this->job->pushed)) return;

		//Log::info('IronJob delete', array('message' => 'this->iron->deleteMessage'));
		//Log::info('IronJob delete', array('job id' => $this->job->id));

		$this->iron->deleteMessage($this->getQueue(), $this->job->id);
	}

	/**
	 * Release the job back into the queue.
	 *
	 * @param  int   $delay
	 * @return void
	 */
	public function release($delay = 0)
	{
		if ( ! $this->pushed) $this->delete();

		$this->recreateJob($delay);
	}

	/**
	 * Release a pushed job back onto the queue.
	 *
	 * @param  int  $delay
	 * @return void
	 */
	protected function recreateJob($delay)
	{
		$payload = json_decode($this->job->body, true);

		array_set($payload, 'attempts', array_get($payload, 'attempts', 1) + 1);

		$this->iron->recreate(json_encode($payload), $this->getQueue(), $delay);
	}

	/**
	 * Get the number of times the job has been attempted.
	 *
	 * @return int
	 */
	public function attempts()
	{
		return array_get(json_decode($this->job->body, true), 'attempts', 1);
	}

	/**
	 * Get the job identifier.
	 *
	 * @return string
	 */
	public function getJobId()
	{
		return $this->job->id;
	}

	/**
	 * Get the underlying Iron queue instance.
	 *
	 * @return \Dcarrith\Queuel\IronQueue
	 */
	public function getIron()
	{
		return $this->iron;
	}

	/**
	 * Get the underlying IronMQ job.
	 *
	 * @return array
	 */
	public function getIronJob()
	{
		return $this->job;
	}

	/**
	 * Get the name of the queue the job belongs to.
	 *
	 * @return string
	 */
	public function getQueue()
	{
		return array_get(json_decode($this->job->body, true), 'queue');
	}

}
