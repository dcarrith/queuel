<?php namespace Dcarrith\Queuel;

use RuntimeException;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\QueueInterface;
use Dcarrith\Queuel\Jobs\SqsJob;
use Log;

class SqsQueue extends PushQueue implements QueueInterface {

	/**
	 * The Amazon SQS instance.
	 *
	 * @var \Aws\Sqs\SqsClient
	 */
	protected $sqs;

	/**
	 * The Amazon SNS instance.
	 *
	 * @var \Aws\Sns\SnsClient
	 */
	protected $sns;

	/**
	 * The name of the default queue.
	 *
	 * @var string
	 */
	protected $queue;

	/**
	 * The account associated with the default queue
	 *
	 * @var string
	 */
	protected $account;

	/**
	 * Create a new Amazon SQS queue instance.
	 *
	 * @param  \Aws\Sqs\SqsClient  $sqs
	 * @param  \Aws\Sns\SnsClient  $sns
	 * @param  \Illuminate\Http\Request  $request
	 * @param  string  $queue
	 * @param  string  $account
	 * @return void
	 */
	public function __construct(SqsClient $sqs, SnsClient $sns, Request $request, $queue, $account)
	{
		$this->sqs = $sqs;
		$this->sns = $sns;
		$this->request = $request;
		$this->queue = $queue;
		$this->account = $account;
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
		Log::info('SqsQueue push', array('queue' => $queue));

		return $this->pushRaw($this->createPayload($job, $data), $queue);
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
		Log::info('SqsQueue pushRaw', array('queue' => $queue));

		$response = $this->sqs->sendMessage(array('QueueUrl' => $this->getQueueUrl($queue), 'MessageBody' => $payload));

		return $response->get('MessageId');
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
		Log::info('SqsQueue later', array('delay' => $delay));
		Log::info('SqsQueue later', array('job' => $job));
		Log::info('SqsQueue later', array('data' => $data));
		Log::info('SqsQueue later', array('queue' => $queue));

		return $this->sqs->sendMessage(array(

			'QueueUrl' => $this->getQueueUrl($queue),
			'MessageBody' => $this->createPayload($job, $data),
			'DelaySeconds' => $this->getSeconds($delay)

		))->get('MessageId');
	}

	/**
	 * Pop the next job off of the queue.
	 *
	 * @param  string  $queue
	 * @return \Illuminate\Queue\Jobs\Job|null
	 */
	public function pop($queue = null)
	{
		Log::info('SqsQueue pop', array('queue'=>$queue));

		$this->queue = $queue;

		$response = $this->sqs->receiveMessage(
			array('QueueUrl' => $this->getQueueUrl($queue), 'AttributeNames' => array('ApproximateReceiveCount'))
		);

		if (count($response['Messages']) > 0)
		{
			Log::info('SqsQueue pop', array('response[Messages][0]' => $response['Messages'][0]));

			return $this->createSqsJob($response['Messages'][0]);
		}
	}

	/**
	 * Marshal a push queue request and fire the job.
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function marshal()
	{
		$r = $this->request;

		Log::info('SqsQueue marshal', array('request->header()'=>$r->header()));
		Log::info('SqsQueue marshal', array('request->json()'=>$r->json()));

		if($r->header('x-amz-sns-message-type') == 'SubscriptionConfirmation')
		{
			$response = $this->getSns()->confirmSubscription(array('TopicArn' => $r->json('TopicArn'), 'Token' => $r->json('Token'), 'AuthenticateOnUnsubscribe' => 'true'));
			Log::info('SqsQueue marshal', array('response' => $response->toArray()));
		}
		else
		{
			$this->createSqsJob($this->marshalPushedJob(), $pushed = true)->fire();
		}

		return new Response('OK');
	}

	/**
	 * Marshal out the pushed job and payload.
	 *
	 * @return array
	 */
	protected function marshalPushedJob()
	{
		$r = $this->request;

		Log::info('SqsQueue marshalPushedJob', array(	'MessageId' => $this->parseOutMessageId($r),
                        					'Body' => $this->parseOutMessage($r),
                        					'pushed' => true,
							    ));

		return array(
			'MessageId' => $this->parseOutMessageId($r),
			'Body' => $this->parseOutMessage($r)
		);
	}

	/**
	 * Create a new SqsJob.
	 *
	 * @param  array  $job
	 * @param  bool	  $pushed
	 * @return \Illuminate\Queue\Jobs\SqsJob
	 */
	protected function createSqsJob($job, $pushed = false)
	{
		Log::info('SqsQueue createSqsJob', array('job' => $job, 'pushed' => $pushed));

		return new SqsJob($this->container, $this, $job, $pushed);
	}

	/**
	 * Get the queue name
	 *
	 * @return string
	 */
	public function getQueue()
	{
		Log::info('SqsQueue getQueue', array('queue' => $this->queue));

		return $this->queue;
	}

	/**
	 * Get the full queue url based on the one passed in or the default.
	 *
	 * @param  string|null  $queue
	 * @return string
	 */
	public function getQueueUrl($queue = null)
	{
		Log::info('SqsQueue getQueueUrl', array('queueUrl' => ($this->sqs->getBaseUrl() . '/' . $this->account . '/' . ($queue ?: $this->queue))));

		return $this->sqs->getBaseUrl() . '/' . $this->account . '/' . ($queue ?: $this->queue);
	}

	/**
	 * Parse out the appropriate message id from the request header
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return string
	 */
	protected function parseOutMessageId($request)
	{
		$snsMessageId = $request->header('x-amz-sns-message-id');
		$sqsMessageId = $request->header('x-aws-sqsd-msgid');

		if(($sqsMessageId == null) && ($snsMessageId == null))
		{
			throw new RuntimeException("The marshaled job must come from either SQS or SNS.");
		}

		return $snsMessageId ?: $sqsMessageId;
	}

	/**
	 * Parse out the message from the request
	 *
	 * @param  Request $request
	 * @return string
	 */
	protected function parseOutMessage($request)
	{
		return stripslashes($request->json('Message'));
	}

	/**
	 * Get the underlying SQS instance.
	 *
	 * @return \Aws\Sqs\SqsClient
	 */
	public function getSqs()
	{
		return $this->sqs;
	}

	/**
	 * Get the underlying SNS instance.
	 *
	 * @return \Aws\Sns\SnsClient
	 */
	public function getSns()
	{
		return $this->sns;
	}

	/**
	 * Get the queue options
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param array   $options
	 * @param array   $advanced
	 * @return array
	 */
	protected function getQueueOptions($queue, $endpoint, $options, $advanced)
	{
		Log::info('SqsQueue getQueueOptions', array('queue' => $queue));
		Log::info('SqsQueue getQueueOptions', array('endpoint' => $endpoint));
		Log::info('SqsQueue getQueueOptions', array('options' => $options));
		Log::info('SqsQueue getQueueOptions', array('advanced' => $advanced));

		$standardOptions = $this->getStandardOptions($queue, $endpoint, $options);

		$newDeliveryPolicy = array('healthyRetryPolicy' => array('numRetries' => intval($standardOptions['retries'])));

		Log::info('SqsQueue update', array('newDeliveryPolicy' => $newDeliveryPolicy));

		if (isset($advanced['healthyRetryPolicy']))
		{
			$newDeliveryPolicy['healthyRetryPolicy'] = array_merge($newDeliveryPolicy['healthyRetryPolicy'], $advanced['healthyRetryPolicy']);
		}

		Log::info('SqsQueue update', array('newDeliveryPolicy' => $newDeliveryPolicy));

		if (isset($advanced['throttlePolicy']))
		{
			$newDeliveryPolicy['throttlePolicy'] = $advanced['throttlePolicy'];
		}

		Log::info('SqsQueue update', array('newDeliveryPolicy' => $newDeliveryPolicy));

		$newRedrivePolicy = array();

		if ($standardOptions['error_queue'] != '')
		{
			$newRedrivePolicy['maxReceiveCount'] = 5;
			$newRedrivePolicy['deadLetterTargetArn'] = $this->getSqs()->getQueueArn($this->getQueueUrl($standardOptions['error_queue']));
		}

		return array('DeliveryPolicy' => $newDeliveryPolicy, 'RedrivePolicy' => $newRedrivePolicy);
	}

	/**
	 * Get the standard queue options.
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param array   $options
	 * @return array
	 */
	protected function getStandardOptions($queue, $endpoint, $options)
	{
		$retries = $this->getOption($queue, $endpoint, 'retries', (isset($options['retries']) ? $options['retries'] : false));

		Log::info('SqsQueue getStandardOptions', array('retries' => $retries));

		$errorQueue = $this->getOption($queue, $endpoint, 'error_queue', (isset($options['errqueue']) ? $options['errqueue'] : false));

		Log::info('SqsQueue getStandardOptions', array('error_queue' => $errorQueue));

		return array('retries' => intval($retries), 'error_queue' => $errorQueue);

		/*
		return array(
			'retries' => $this->getOption('retries', $queue, (isset($options['retries']) ? $options['retries'] : false)),
			'error_queue' => $this->getOption('error_queue', $queue, (isset($options['errqueue']) ? $options['errqueue'] : false))
		);
		*/
	}

	/**
	 * Maps standard keys to dot paths for the meta array
	 *
	 * @param string  $key
	 * @return string
	 */
	protected function getDotPath($key)
	{
		$mapping = array('retries' => 'DeliveryPolicy.numRetries',
				 'error_queue' => 'QueueAttributes.RedrivePolicy.deadLetterTargetArn');

		return $mapping[$key];
	}

	/**
	 * Get a queue option
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param string  $key
	 * @param string  $value
	 * @return string
	 */
	protected function getOption($queue, $endpoint, $key, $value = null)
	{
		if ($value) return $value;

		try
		{
			Log::info('SqsQueue getOption', array('getQueueMeta' => $this->getQueueMeta($queue, $endpoint)));

			return array_get($this->getQueueMeta($queue, $endpoint), $this->getDotPath($key));

			//return $this->getQueueMeta($queue, $endpoint)['DeliveryPolicy'][$key];
		}
		catch (\Exception $e)
		{
			switch($key) {
				case 'retries':
					return 3;
				case 'error_queue':
					return '';
				default :
					throw new RuntimeException("The option '".$key."' is not a valid setting for an SQS queue");
			}
		}
	}

	/**
	 * Get the queue information from SQS
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @return object
	 */
	protected function getQueueMeta($queue, $endpoint)
	{
		if (isset($this->meta))
		{
			Log::info('SqsQueue getQueueMeta', array('this->meta' => $this->meta));

			return $this->meta;
		}

		$meta = array();

		$meta['QueueAttributes'] = $this->getSqs()->getQueueAttributes(array('QueueUrl' => $this->getQueueUrl($queue),
										     'Attributes' => 'All'));

		Log::info('SqsQueue getQueueMeta', array('meta' => $meta));

		$meta['SubscriptionArn'] = $this->getCurrentSubscriptionArn($queue, $endpoint);

		Log::info('SqsQueue getQueueMeta', array('meta' => $meta));

		$meta['DeliveryPolicy'] = $this->getCurrentDeliveryPolicy($meta['SubscriptionArn']);

		Log::info('SqsQueue getQueueMeta', array('meta' => $meta));

		return $this->meta = $meta;

		/*
		if (isset($this->meta)) return $this->meta;

		return $this->meta = (array)$this->getIron()->getQueue($queue);
		*/
	}

	/**
	 * Get the SubscriberArn for the current subscription
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @return string
	 */
	protected function getCurrentSubscriptionArn($queue, $endpoint)
	{
		$response = $this->getSns()->listSubscriptions();

		Log::info('SqsQueue update', array('listSubscriptions' => $response->toArray()));

		$subscription = array_values(array_filter($response->toArray()['Subscriptions'], function($element) use ($endpoint) {

			return $element['Endpoint'] == $endpoint;
		}));

		Log::info('SqsQueue update', array('subscription' => $subscription));

		if ( ! count($subscription)) throw new RuntimeException("Can't find any subscriptions for the '".$queue."' topic.");

		return $subscription[0]['SubscriptionArn'];
	}

	/**
	 * Get the SubscriberArn for the current subscription
	 *
	 * @param string $arn
	 * @return array
	 */
	protected function getCurrentDeliveryPolicy($arn)
	{
		$response = $this->getSns()->getSubscriptionAttributes(array('SubscriptionArn' => $arn));

		Log::info('SqsQueue getCurrentDeliveryPolicy', array('response' => $response->toArray()));

		$existingDeliveryPolicy = json_decode(stripslashes($response->toArray()['Attributes']['EffectiveDeliveryPolicy']), true);

		Log::info('SqsQueue getCurrentDeliveryPolicy', array('existingDeliveryPolicy' => $existingDeliveryPolicy));

		return $existingDeliveryPolicy;
	}

	/**
	 * Subscribe a queue to the endpoint url
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @param array   $options
	 * @param array   $advanced
	 * @return array
	 */
	public function subscribe($queue, $endpoint, array $options = array(), array $advanced = array())
	{
		Log::info('SqsQueue subscribe', array('queue' => $queue));
		Log::info('SqsQueue subscribe', array('endpoint' => $endpoint));
		Log::info('SqsQueue subscribe', array('options' => $options));

		$topicArn = $this->getSns()->createTopic(array('Name' => $queue))->get('TopicArn');

		Log::info('SqsQueue subscribe', array('createTopic response->get(TopicArn)' => $topicArn));

		$response = $this->getSns()->subscribe(array('TopicArn' => $topicArn, 'Protocol' => ((stripos($endpoint, 'https') !== false) ? 'https' : 'http'), 'Endpoint' => $endpoint));

		Log::info('SqsQueue subscribe', array('response' => $response->toArray()));

		return $response->toArray();
	}

	/**
	 * Unsubscribe a queue from an endpoint url
	 *
	 * @param string  $queue
	 * @param string  $endpoint
	 * @return array
	 */
	public function unsubscribe($queue, $endpoint)
	{
		Log::info('SqsQueue unsubscribe', array('list' => 'subscriptions'));

		$response = $this->getSns()->listSubscriptions();

		Log::info('SqsQueue unsubscribe', array('listSubscriptions' => $response->toArray()));

		$subscription = array_values(array_filter($response->toArray()['Subscriptions'], function($element) use ($endpoint) {

			return $element['Endpoint'] == $endpoint;
		}));

		Log::info('SqsQueue unsubscribe', array('subscription' => $subscription));

		if(count($subscription))
		{
			$response = $this->getSns()->unsubscribe(array('SubscriptionArn' => $subscription[0]['SubscriptionArn']));
		}

		Log::info('SqsQueue unsubscribe', array('response' => $response->toArray()));

		return $response->toArray();
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
		Log::info('SqsQueue update', array('queue' => $queue));
		Log::info('SqsQueue update', array('endpoint' => $endpoint));
		Log::info('SqsQueue update', array('options' => $options));
		Log::info('SqsQueue update', array('advanced' => $advanced));

		$queueOptions = $this->getQueueOptions($queue, $endpoint, $options, $advanced);

		Log::info('SqsQueue update', array('queueOptions' => $queueOptions));

		$newDeliveryPolicy = $queueOptions['DeliveryPolicy'];

		Log::info('SqsQueue update', array('newDeliveryPolicy' => $newDeliveryPolicy));

		$subscriptionArn = array_get($this->getQueueMeta($queue, $endpoint), 'SubscriptionArn');

		Log::info('SqsQueue update', array('subscriptionArn' => $subscriptionArn));

		$response = $this->getSns()->setSubscriptionAttributes(array('SubscriptionArn' => $subscriptionArn,
									     'AttributeName' => 'DeliveryPolicy',
									     'AttributeValue' => json_encode($newDeliveryPolicy)));

		Log::info('SqsQueue update', array('response' => $response->toArray()));

		$newRedrivePolicy = $queueOptions['RedrivePolicy'];

		$response = $this->getSqs()->setQueueAttributes(array('QueueUrl' => $this->getQueueUrl($queue),
								      'Attributes' => array('RedrivePolicy' => json_encode($newRedrivePolicy))));

		Log::info('SqsQueue update', array('response' => $response->toArray()));

		return $response->toArray();
	}

}
