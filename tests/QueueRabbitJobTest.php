<?php

use Mockery as m;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Http\Response;
use Dcarrith\Queuel\Jobs\RabbitJob;

class QueueRabbitJobTest extends PHPUnit_Framework_TestCase {

	public function tearDown()
	{
		m::close();
	}

	public function setUp() {

		$this->mockedConnection = m::mock('PhpAmqpLib\Connection\AMQPConnection');
		$this->mockedChannel = m::mock('PhpAmqpLib\Channel\AMQPChannel');
		$this->mockedContainer = m::mock('Illuminate\Container\Container');

		$this->host = 'localhost';
		$this->port = '5672';
		$this->username = 'foo';
		$this->password = 'foo';
		$this->queue = 'default';

		$this->job = 'foo';
		$this->data = array('data');
		$this->payload = json_encode(array('job' => $this->job, 'data' => $this->data, 'attempts' => 1, 'queue' => $this->queue));
		$this->recreatedPayload = json_encode(array('job' => $this->job, 'data' => $this->data, 'attempts' => 2, 'queue' => $this->queue));

		$this->message = new AMQPMessage($this->payload, array('delivery_mode' => 2));
		$this->message->delivery_info = array(
			"channel" => 'foo',
			"consumer_tag" => 'bar',
			"delivery_tag" => 1,
			"redelivered" => false,
			"exchange" => "",
			"routing_key" => $this->queue
		);
	}

	public function testFireProperlyCallsTheJobHandler()
	{
		$this->mockedConnection->shouldReceive('channel')->once()->andReturn($this->mockedChannel);
		$queue = $this->getMock('Dcarrith\Queuel\RabbitQueue', array('getQueue'), array($this->mockedConnection, $this->queue));
		$queue->setContainer($this->mockedContainer);
		$job = $this->getJob($queue);
		$job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock('StdClass'));
		$handler->shouldReceive('fire')->once()->with($job, $this->data);
		$job->fire();
	}

	public function testReleaseDeletesMessageThenRecreatesJob()
	{
		$this->mockedConnection->shouldReceive('channel')->once()->andReturn($this->mockedChannel);
		$queue = $this->getMock('Dcarrith\Queuel\RabbitQueue', array('getQueue', 'recreate', 'pushRaw'), array($this->mockedConnection, $this->queue));
		$queue->setContainer($this->mockedContainer);
                $queue->expects($this->any())->method('getQueue')->with(null)->will($this->returnValue($this->queue));
		$this->mockedChannel->shouldReceive('basic_ack')->once()->with($this->message->delivery_info['delivery_tag'])->andReturn(null);
		$queue->expects($this->once())->method('recreate')->with($this->recreatedPayload, $this->queue, 0)->will($this->returnValue(new Response('Ok')));
		$job = $this->getJob($queue);
		$job->release();
	}

	public function testAttemptsReturnsCurrentCountOfAttempts()
	{
		$this->mockedConnection->shouldReceive('channel')->once()->andReturn($this->mockedChannel);
		$queue = $this->getMock('Dcarrith\Queuel\RabbitQueue', array('getQueue'), array($this->mockedConnection, $this->queue));
		$queue->setContainer($this->mockedContainer);
		$job = $this->getJob($queue);
		$one = $job->attempts();
		$this->assertEquals($one, 1);
	}

	protected function getJob($queue)
	{
		return new RabbitJob(
			$this->mockedContainer,
			$queue,
			$this->message
		);
	}

}
