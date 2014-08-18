<?php namespace Dcarrith\Queuel;

use Closure;
use DateTime;
use Illuminate\Container\Container;
use Illuminate\Support\SerializableClosure;
use Illuminate\Support\Facades\Config;
use Illuminate\Queue\QueueInterface;
use Illuminate\Queue\Queue;

abstract class Queuel extends Queue implements QueueInterface {

	/**
	 * Marshal a push queue request and fire the job.
	 *
	 * @throws \RuntimeException
	 */
	public function marshal()
	{
		// Override the default runtime exception message to add in SQS to th message
		throw new \RuntimeException("Push queues only supported by Iron and SQS.");
	}

	/**
	 * The base subscribe function just throws a runtime exception to let the user know it's not supported.
	 *  Derived classes that support push queues are required to implement an overriding subscribe function.
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param array   $options
	 * @param array   $advanced
	 * @throws \RuntimeException
	 */
	public function subscribe($queue, $endpoint, array $options = array(), array $advanced = array())
	{
		throw new \RuntimeException("The default queue driver '".Config::get('queue.default')."' doesn't support the subscribe command.");
	}

	/**
	 * This base unsubscribe function just throws a runtime exception to let the user know it's not supported.
	 *  Derived classes that support push queues are required to implement an overriding unsubscribe function.
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @throws \RuntimeException
	 */
	public function unsubscribe($queue, $endpoint)
	{
		throw new \RuntimeException("The default queue driver '".Config::get('queue.default')."' doesn't support the unsubscribe command.");
	}

	/**
	 * The base update function just throws a runtime exception to let the user know it's not supported.
	 *  Derived classes that support push queues are required to implement an overriding subscribe function.
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param array   $options
	 * @param array   $advanced
	 * @throws \RuntimeException
	 */
	public function update($queue, $endpoint, array $options = array(), array $advanced = array())
	{
		throw new \RuntimeException("The default queue driver '".Config::get('queue.default')."' doesn't support the update command.");
	}

}
