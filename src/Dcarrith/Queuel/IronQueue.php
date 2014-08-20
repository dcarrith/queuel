<?php namespace Dcarrith\Queuel;

use IronMQ;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Encryption\Encrypter;
use Illuminate\Queue\QueueInterface;
use Dcarrith\Queuel\Jobs\IronJob;
use RuntimeException;
//use Log;

class IronQueue extends PushQueue implements QueueInterface {

	/**
	 * The IronMQ instance.
	 *
	 * @var IronMQ
	 */
	protected $iron;

	/**
	 * The queue meta information from Iron.io.
	 *
	 * @var object
	 */
	protected $meta;

	/**
	 * The encrypter instance.
	 *
	 * @var \Illuminate\Encryption\Encrypter
	 */
	protected $crypt;

	/**
	 * The name of the default tube.
	 *
	 * @var string
	 */
	protected $default;

	/**
	 * Indicates if the messages should be encrypted.
	 *
	 * @var bool
	 */
	protected $shouldEncrypt;

	/**
	 * Create a new IronMQ queue instance.
	 *
	 * @param  \IronMQ  $iron
	 * @param  \Illuminate\Encryption\Encrypter  $crypt
	 * @param  \Illuminate\Http\Request  $request
	 * @param  string  $default
	 * @param  bool  $shouldEncrypt
	 * @return void
	 */
	public function __construct(IronMQ $iron, Encrypter $crypt, Request $request, $default, $shouldEncrypt = false)
	{
		$this->iron = $iron;
		$this->crypt = $crypt;
		$this->request = $request;
		$this->default = $default;
		$this->shouldEncrypt = $shouldEncrypt;
	}

	/**
	 * Push a new job onto the queue.
	 *
	 * @param  string  $job
	 * @param  mixed   $data
	 * @param  string  $queue
	 * @return mixed
	 */
	public function push($job, $data = '', $queue = null)
	{
		return $this->pushRaw($this->createPayload($job, $data, $queue), $queue);
	}

	/**
	 * Push a raw payload onto the queue.
	 *
	 * @param  string  $payload
	 * @param  string  $queue
	 * @param  array   $options
	 * @return mixed
	 */
	public function pushRaw($payload, $queue = null, array $options = array())
	{
		if ($this->shouldEncrypt) $payload = $this->crypt->encrypt($payload);

		return $this->iron->postMessage($this->getQueue($queue), $payload, $options)->id;
	}

	/**
	 * Push a raw payload onto the queue after encrypting the payload.
	 *
	 * @param  string  $payload
	 * @param  string  $queue
	 * @param  int     $delay
	 * @return mixed
	 */
	public function recreate($payload, $queue = null, $delay)
	{
		$options = array('delay' => $this->getSeconds($delay));

		return $this->pushRaw($payload, $queue, $options);
	}

	/**
	 * Push a new job onto the queue after a delay.
	 *
	 * @param  \DateTime|int  $delay
	 * @param  string  $job
	 * @param  mixed   $data
	 * @param  string  $queue
	 * @return mixed
	 */
	public function later($delay, $job, $data = '', $queue = null)
	{
		$delay = $this->getSeconds($delay);

		$payload = $this->createPayload($job, $data, $queue);

		return $this->pushRaw($payload, $this->getQueue($queue), compact('delay'));
	}

	/**
	 * Pop the next job off of the queue.
	 *
	 * @param  string  $queue
	 * @return \Illuminate\Queue\Jobs\Job|null
	 */
	public function pop($queue = null)
	{
		$queue = $this->getQueue($queue);

		$job = $this->iron->getMessage($queue);

		//Log::info('IronQueue pop', array('job'=>json_encode((array)$job)));

		// If we were able to pop a message off of the queue, we will need to decrypt
		// the message body, as all Iron.io messages are encrypted, since the push
		// queues will be a security hazard to unsuspecting developers using it.
		if ( ! is_null($job))
		{
			$job->body = $this->parseJobBody($job->body);

			return $this->createIronJob($job);
		}
	}

	/**
	 * Delete a message from the Iron queue.
	 *
	 * @param  string  $queue
	 * @param  string  $id
	 * @return void
	 */
	public function deleteMessage($queue, $id)
	{
		$this->iron->deleteMessage($queue, $id);
	}

	/**
	 * Marshal a push queue request and fire the job.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function marshal()
	{
		$r = $this->request;

		//Log::info('IronQueue marshal', array('request->header()'=>$r->header()));
		//Log::info('IronQueue marshal', array('request->json()'=>$r->json()));

		$this->createIronJob($this->marshalPushedJob(), true)->fire();

		return new Response('OK');
	}

	/**
	 * Marshal out the pushed job and payload.
	 *
	 * @return object
	 */
	protected function marshalPushedJob()
	{
		$r = $this->request;

		$messageId = $this->parseOutMessageId($r);
		$body = $this->parseJobBody($r->getContent());

		$pushedJob = array('id' => $messageId, 'body' => $body, 'pushed' => true);

		//Log::info('IronQueue marshalPushedJob', $pushedJob);

		return (object)$pushedJob;
	}

	/**
	 * Create a new IronJob.
	 *
	 * @param  object  $job
	 * @param  boolean $pushed
	 * @return \Dcarrith\Queuel\Jobs\IronJob
	 */
	protected function createIronJob($job, $pushed = false)
	{
		return new IronJob($this->container, $this, $job, $pushed);
	}

	/**
	 * Create a payload string from the given job and data.
	 *
	 * @param  string  $job
	 * @param  mixed   $data
	 * @param  string  $queue
	 * @return string
	 */
	protected function createPayload($job, $data = '', $queue = null)
	{
		$payload = $this->setMeta(parent::createPayload($job, $data), 'attempts', 1);

		return $this->setMeta($payload, 'queue', $this->getQueue($queue));
	}

	/**
	 * Parse out the message id from the request header
	 *
	 * @param  Request $request
	 * @return string
	 */
	protected function parseOutMessageId($request)
	{
		$messageId = $request->header('iron-message-id');

		if($messageId == null)
		{
			throw new RuntimeException("The marshaled job must come from IronMQ.");
		}

		return $messageId;
	}

	/**
	 * Parse the job body for firing.
	 *
	 * @param  string  $body
	 * @return string
	 */
	protected function parseJobBody($body)
	{
		return $this->shouldEncrypt ? $this->crypt->decrypt($body) : $body;
	}

	/**
	 * Get the queue or return the default.
	 *
	 * @param  string|null  $queue
	 * @return string
	 */
	public function getQueue($queue)
	{
		return $queue ?: $this->default;
	}

	/**
	 * Get the underlying IronMQ instance.
	 *
	 * @return IronMQ
	 */
	public function getIron()
	{
		return $this->iron;
	}

	/**
	 * Get the queue options.
	 *
	 * @return array
	 */
	protected function getQueueOptions($queue, $endpoint, $options, $advanced)
	{
		$subscribers = $this->getSubscriberList($queue, $endpoint);

		//Log::info('IronQueue getQueueOptions', array('subscribers' => $subscribers));

		$standardOptions = $this->getStandardOptions($queue, $endpoint, $options);

		//Log::info('IronQueue getQueueOptions', array('standardOptions' => $standardOptions));

		$advancedOptions = array_merge(array_only($this->getQueueMeta($queue), array('push_type', 'retries_delay')), $advanced);

		//Log::info('IronQueue getQueueOptions', array('advancedOptions' => $advancedOptions));

		return array_merge(array('subscribers' => $subscribers), $standardOptions, $advancedOptions);

		/*
		return array_merge(
			array('subscribers' => $this->getSubscriberList($queue, $endpoint)),
			$this->getStandardOptions($queue, $endpoint, $options),
			array_merge(array_only($this->getQueueMeta($queue), array('push_type', 'retries_delay')), $advanced)
		);
		*/
	}

	/**
	 * Get the standard queue options.
	 *
	 * @return array
	 */
	protected function getStandardOptions($queue, $endpoint, $options)
	{
		$retries = $this->getOption('retries', $queue, (isset($options['retries']) ? $options['retries'] : false));

		//Log::info('IronQueue getStandardOptions', array('retries' => $retries));

		$errorQueue = $this->getOption('error_queue', $queue, (isset($options['errqueue']) ? $options['errqueue'] : false));

		//Log::info('IronQueue getStandardOptions', array('error_queue' => $errorQueue));

		return array('retries' => intval($retries), 'error_queue' => $errorQueue);

		/*
		return array(
			'retries' => $this->getOption('retries', $queue, (isset($options['retries']) ? $options['retries'] : false)),
			'error_queue' => $this->getOption('error_queue', $queue, (isset($options['errqueue']) ? $options['errqueue'] : false))
		);
		*/
	}

	/**
	 * Get a queue option
	 *
	 * @return string
	 */
	protected function getOption($key, $queue, $value = null)
	{
		if ($value) return $value;

		try
		{
			//Log::info('IronQueue getOption', array('getQueueMeta' => $this->getQueueMeta($queue)));

			return $this->getQueueMeta($queue)[$key];
		}
		catch (\Exception $e)
		{
			switch($key) {
				case 'retries':
					return 3;
				case 'error_queue':
					return '';
				default :
					throw new RuntimeException("The option '".$key."' is not a valid setting for an Iron.io queue");
			}
		}
	}

	/**
	 * Get the queue information from Iron.io.
	 *
	 * @return object
	 */
	protected function getQueueMeta($queue)
	{
		if (isset($this->meta))
		{
			//Log::info('IronQueue getQueueMeta', array('this->meta' => $this->meta));

			return $this->meta;
		}

		$queueMeta = (array)$this->getIron()->getQueue($queue);

		//Log::info('IronQueue getQueueMeta', array('queueMeta' => $queueMeta));

		return $this->meta = $queueMeta;

		/*
		if (isset($this->meta)) return $this->meta;

		return $this->meta = (array)$this->getIron()->getQueue($queue);
		*/
	}

	/**
	 * Get the current subscribers for the queue.
	 *
	 * @return array
	 */
	protected function getSubscriberList($queue, $endpoint)
	{
		$subscribers = $this->getCurrentSubscribers($queue);

		$subscribers = array_map(function($subscriber) {

			return (array)$subscriber;

		}, $subscribers);

		//Log::info('IronQueue getSubscriberList', array('subscribers' => $subscribers));

		//Log::info('IronQueue getSubscriberList', array('array_column subscribers' => array_column($subscribers, 'url')));

		//Log::info('IronQueue getSubscriberList', array('array_search subscribers' => array_search($endpoint, array_column($subscribers, 'url'))));

		if(array_search($endpoint, array_column($subscribers, 'url')) === false)
		{
			$subscribers[] = array('url' => $endpoint);
		}

		return $subscribers;
	}

	/**
	 * Get the current subscriber list.
	 *
	 * @return array
	 */
	protected function getCurrentSubscribers($queue)
	{
		try
		{
			return $this->getQueueMeta($queue)['subscribers'];
		}
		catch (\Exception $e)
		{
			return array();
		}
	}

	/**
	 * Subscribe a queue to the endpoint url
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param array   $options
	 * @return array
	 */
	public function subscribe($queue, $endpoint, array $options = array(), array $advanced = array())
	{
		//Log::info('IronQueue subscribe', array('queue' => $queue));
		//Log::info('IronQueue subscribe', array('endpoint' => $endpoint));
		//Log::info('IronQueue subscribe', array('options' => $options));
		//Log::info('IronQueue subscribe', array('advanced' => $advanced));

		return $this->update($queue, $endpoint, $options, $advanced);
	}

	/**
	 * Unsubscribe a queue from an endpoint url
	 *
	 * @param string  $queue
	 * @param string  $url
	 * @return array
	 */
	public function unsubscribe($queue, $endpoint)
	{
		//Log::info('IronQueue unsubscribe', array('queue' => $queue));
		//Log::info('IronQueue unsubscribe', array('endpoint' => $endpoint));

		$response = $this->getIron()->removeSubscriber($queue, array('url' => $endpoint));

		//Log::info('IronQueue unsubscribe', array('removeSubscriber response' => $response));

		return $response;
		//return $this->getIron()->removeSubscriber($queue, array('url' => $endpoint));
	}

	/**
	 * Update queue settings
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param array   $options
	 * @param array   $advanced
	 * @return array
	 */
	public function update($queue, $endpoint, array $options = array(), array $advanced = array())
	{
		//Log::info('IronQueue update', array('queue' => $queue));
		//Log::info('IronQueue update', array('endpoint' => $endpoint));
		//Log::info('IronQueue update', array('options' => $options));
		//Log::info('IronQueue update', array('advanced' => $advanced));

		$response = $this->getIron()->updateQueue($queue, $this->getQueueOptions($queue, $endpoint, $options, $advanced));

		//Log::info('IronQueue update', array('updateQueue response' => $response));

		return $response;
		//return $this->getIron()->updateQueue($queue, $this->getQueueOptions($queue, $endpoint, $options, $advanced));
	}

}

