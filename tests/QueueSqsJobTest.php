<?php

use Mockery as m;

use Aws\Sqs\SqsClient;
use Aws\Common\Credentials\Credentials;
use Aws\Common\Credentials\CredentialsInterface;
use Aws\Common\Signature\SignatureInterface;
use Aws\Common\Signature\SignatureV4;

use Guzzle\Common\Collection;
use Guzzle\Service\Resource\Model;

class QueueSqsJobTest extends PHPUnit_Framework_TestCase {

	public function setUp() {

		$this->key = 'AMAZONSQSKEY';
		$this->secret = 'AmAz0n+SqSsEcReT+aLpHaNuM3R1CsTr1nG';
		$this->service = 'sqs';
		$this->region = 'someregion';
		$this->account = '1234567891011';
		$this->queueName = 'emails';
		$this->pushQueueName = 'notifications';
		$this->baseUrl = 'https://sqs.someregion.amazonaws.com';
		$this->releaseDelay = 0;

		$this->credentials = new Credentials( $this->key, $this->secret );
		$this->signature = new SignatureV4( $this->service, $this->region );
		$this->config = new Collection();

		$this->queueUrl = $this->baseUrl . '/' . $this->account . '/' . $this->queueName;
		$this->pushQueueUrl = $this->baseUrl . '/' . $this->account . '/' . $this->pushQueueName;

		$this->mockedSqsClient = $this->getMock('Aws\Sqs\SqsClient', array('deleteMessage'), array($this->credentials, $this->signature, $this->config));

		$this->sns = m::mock('Aws\Sns\SnsClient');

		$this->mockedContainer = m::mock('Illuminate\Container\Container');

		$this->mockedJob = 'foo';
		$this->mockedData = array('data');
		$this->mockedPayload = json_encode(array('job' => $this->mockedJob, 'data' => $this->mockedData, 'attempts' => 1));
		$this->mockedMessageId = 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81';
		$this->mockedReceiptHandle = '0NNAq8PwvXuWv5gMtS9DJ8qEdyiUwbAjpp45w2m6M4SJ1Y+PxCh7R930NRB8ylSacEmoSnW18bgd4nK\/O6ctE+VFVul4eD23mA07vVoSnPI4F\/voI1eNCp6Iax0ktGmhlNVzBwaZHEr91BRtqTRM3QKd2ASF8u+IQaSwyl\/DGK+P1+dqUOodvOVtExJwdyDLy1glZVgm85Yw9Jf5yZEEErqRwzYz\/qSigdvW4sm2l7e4phRol\/+IjMtovOyH\/ukueYdlVbQ4OshQLENhUKe7RNN5i6bE\/e5x9bnPhfj2gbM';

		$this->mockedTopicArn = 'arn:aws:sns:'.$this->region.':'.$this->account.':'.$this->pushQueueName;

		$this->mockedJobData = array('Body' => $this->mockedPayload,
					     'MD5OfBody' => md5($this->mockedPayload),
					     'ReceiptHandle' => $this->mockedReceiptHandle,
					     'MessageId' => $this->mockedMessageId,
					     'Attributes' => array('ApproximateReceiveCount' => 1));

		$this->mockedReceiveMessageResponseModel = new Model(array('Messages' => array( 0 => array(
												'Body' => $this->mockedPayload,
						     						'MD5OfBody' => md5($this->mockedPayload),
						      						'ReceiptHandle' => $this->mockedReceiptHandle,
						     						'MessageId' => $this->mockedMessageId))));
	}

	public function tearDown()
	{
		m::close();
	}

	public function testFireProperlyCallsTheJobHandler()
	{
		$this->mockedSqsClient = $this->getMock('Aws\Sqs\SqsClient', array('deleteMessage'), array($this->credentials, $this->signature, $this->config));
		$queue = $this->getMock('Dcarrith\Queuel\SqsQueue', array('getQueueUrl'), array($this->mockedSqsClient, $this->sns, m::mock('Illuminate\Http\Request'), $this->queueName, $this->account));
		$queue->setContainer($this->mockedContainer);
		$job = $this->getJob($queue);
		$job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock('StdClass'));
		$handler->shouldReceive('fire')->once()->with($job, array('data'));
		$job->fire();
	}

	public function testDeleteRemovesTheJobFromSqs()
	{
		$this->mockedSqsClient = $this->getMock('Aws\Sqs\SqsClient', array('receiveMessage', 'deleteMessage'), array($this->credentials, $this->signature, $this->config));
		$queue = $this->getMock('Dcarrith\Queuel\SqsQueue', array('getQueueUrl'), array($this->mockedSqsClient, $this->sns, $request = m::mock('Illuminate\Http\Request'), $this->queueName, $this->account));
		$queue->setContainer($this->mockedContainer);
		$queue->expects($this->at(0))->method('getQueueUrl')->with(null)->will($this->returnValue($this->queueUrl));
		$job = $this->getJob($queue);
		$job->getSqsQueue()->getSqs()->expects($this->once())->method('deleteMessage')->with(array('QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle));
		$job->delete();
	}

	public function testDeleteRemovesThePushedJobFromSqs()
	{
		$this->mockedSqsClient = $this->getMock('Aws\Sqs\SqsClient', array('receiveMessage', 'deleteMessage'), array($this->credentials, $this->signature, $this->config));
		$queue = $this->getMock('Dcarrith\Queuel\SqsQueue', array('getQueueUrl'), array($this->mockedSqsClient, $this->sns, $request = m::mock('Illuminate\Http\Request'), $this->queueName, $this->account));
		$queue->setContainer($this->mockedContainer);
		$queue->expects($this->at(0))->method('getQueueUrl')->with(null)->will($this->returnValue($this->queueUrl));
		$queue->expects($this->at(1))->method('getQueueUrl')->with($this->pushQueueName)->will($this->returnValue($this->pushQueueUrl));
		$request->shouldReceive('header')->once()->with('x-amz-sns-topic-arn')->andReturn($this->mockedTopicArn);
		$job = $this->getJob($queue, true);
		$job->getSqsQueue()->getSqs()->expects($this->once())->method('receiveMessage')->with(array('QueueUrl' => $this->pushQueueUrl))->will($this->returnValue($this->mockedReceiveMessageResponseModel));
		$job->getSqsQueue()->getSqs()->expects($this->once())->method('deleteMessage')->with(array('QueueUrl' => $this->pushQueueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle));
		$job->delete();
	}

	public function testReleaseProperlyReleasesTheJobOntoSqs()
	{
		$this->mockedSqsClient = $this->getMock('Aws\Sqs\SqsClient', array('changeMessageVisibility'), array($this->credentials, $this->signature, $this->config));
		$queue = $this->getMock('Dcarrith\Queuel\SqsQueue', array('getQueueUrl'), array($this->mockedSqsClient, $this->sns, $request = m::mock('Illuminate\Http\Request'), $this->queueName, $this->account));
		$queue->setContainer($this->mockedContainer);
		$queue->expects($this->at(0))->method('getQueueUrl')->with(null)->will($this->returnValue($this->queueUrl));
		$job = $this->getJob($queue);
		$job->getSqsQueue()->getSqs()->expects($this->once())->method('changeMessageVisibility')->with(array('QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle, 'VisibilityTimeout' => $this->releaseDelay));
		$job->release($this->releaseDelay);
	}

	protected function getJob($queue, $pushed = false)
	{
		return new Dcarrith\Queuel\Jobs\SqsJob(
			$this->mockedContainer,
			$queue,
			$this->mockedJobData,
			$pushed
		);
	}

}
