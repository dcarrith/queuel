<?php namespace Dcarrith\Queuel\Jobs;

use RuntimeException;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job;
use Dcarrith\Queuel\SqsQueue;
use Log;

class SqsJob extends Job {

	/**
	 * The job is the response from the sqs receiveMessage.
	 *
	 * @var array
	 */
	protected $job;

	/**
	 * The SqsQueue instance
	 *
	 * @var \Illuminate\Queue\SqsQueue
	 */
	protected $sqsQueue;

	/**
	 * Indicates if the message was a push message.
	 *
	 * @var bool
	 */
	protected $pushed;

	/**
	 * Create a new job instance.
	 *
	 * @param  \Illuminate\Container\Container  $container
	 * @param  \Illuminate\Queue\SqsQueue  $queue
	 * @param  array   $job
	 * @param  boolean $pushed
	 * @return void
	 */
	public function __construct(Container $container,
                                SqsQueue $sqsQueue,
                                array $job,
				$pushed = false)
	{
		$this->container = $container;
		$this->sqsQueue = $sqsQueue;
		$this->job = $job;
		$this->pushed = $pushed;
		$this->queue = $this->sqsQueue->getQueue();
	}

	/**
	 * Fire the job.
	 *
	 * @return void
	 */
	public function fire()
	{
		$this->resolveAndFire(json_decode($this->getRawBody(), true));
	}

	/**
	 * Get the raw body string for the job.
	 *
	 * @return string
	 */
	public function getRawBody()
	{
		return $this->job['Body'];
	}

	/**
	 * Delete the job from the queue.
	 *
	 * @return void
	 */
	public function delete()
	{
		Log::info('SqsJob delete', array('message' => 'calling parent::delete()'));

		parent::delete();

		$queueUrl = $this->getSqsQueue()->getQueueUrl();

		Log::info('SqsJob delete', array('message' => 'checking if isset(this->isPushed())'));

		if ($this->isPushed())
		{
			$r = $this->getSqsQueue()->getRequest();

			$topic = $this->parseTopicArn($r, 'topic');

			Log::info('SqsJob delete', array('topic' => $topic));

			$queueUrl = $this->getSqsQueue()->getQueueUrl($topic);

			Log::info('SqsJob delete', array('QueueUrl' => $queueUrl));

			$response = $this->getSqsQueue()->getSqs()->receiveMessage(array(

				'QueueUrl' => $queueUrl

			));

			Log::info('SqsJob delete', array('response' => $response->toArray()));

			$receiptHandle = $response->toArray()['Messages'][0]['ReceiptHandle'];
		}
		else
		{
			Log::info('SqsJob delete else', array('QueueUrl' => $queueUrl));

			$receiptHandle = $this->job['ReceiptHandle'];
		}

		Log::info('SqsJob delete', array('receiptHandle' => $receiptHandle));

		$this->getSqsQueue()->getSqs()->deleteMessage(array(

			'QueueUrl' => $queueUrl, 'ReceiptHandle' => $receiptHandle

		));
	}

	/**
	 * Parse the topic arn for a specific piece of data
	 *
	 * @param  string  $piece
	 * @return string
	 */
	public function parseTopicArn($request, $piece)
	{
		$pieces = array('arn', 'aws', 'service', 'region', 'account', 'topic');

		if( ! in_array($piece, $pieces)) throw new RuntimeException("The target piece is not part of the TopicArn.");

		Log::info('SqsJob parseTopicArn', array('TopicArn' => $request->header('x-amz-sns-topic-arn')));

		list($arn, $aws, $service, $region, $account, $topic) = explode(":", $request->header('x-amz-sns-topic-arn'));

		Log::info('SqsJob parseTopicArn', array('piece' => $piece, 'retrieved' => compact($pieces)[$piece]));

		return compact($pieces)[$piece];
	}

	/**
	 * Release the job back into the queue.
	 *
	 * @param  int   $delay
	 * @return void
	 */
	public function release($delay = 0)
	{
		// SQS job releases are handled by the server configuration...
	}

	/**
	 * Get the number of times the job has been attempted.
	 *
	 * @return int
	 */
	public function attempts()
	{
		return (int) $this->job['Attributes']['ApproximateReceiveCount'];
	}

	/**
	 * Get the job identifier.
	 *
	 * @return string
	 */
	public function getJobId()
	{
		return $this->job['MessageId'];
	}

	/**
	 * Get the underlying raw SQS job.
	 *
	 * @return array
	 */
	public function getSqsJob()
	{
		return $this->job;
	}

	/**
	 * Get the underlying raw SqsQueue.
	 *
	 * @return Illuminate\Queue\SqsQueue
	 */
	public function getSqsQueue()
	{
		return $this->sqsQueue;
	}

	/**
	 * Check whether this is a pushed job
	 *
	 * @return boolean
	 */
	public function isPushed()
	{
		return $this->pushed;
	}

	/**
	 * Get the name of the queue the job belongs to.
	 *
	 * @return string
	 */
	public function getQueue()
	{
		return $this->queue;
	}

}
