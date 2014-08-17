<?php

use Mockery as m;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Http\Response;

class QueueRabbitQueueTest extends PHPUnit_Framework_TestCase {

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
		$this->payload = json_encode(array('job' => $this->job, 'data' => $this->data));
		$this->delay = 10;
		$this->messageId = 'messageid';

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

	public function testPushProperlyPushesJobOntoRabbit()
	{
		$this->mockedConnection->shouldReceive('channel')->once()->andReturn($this->mockedChannel);
		$queue = $this->getMock('Illuminate\Queue\RabbitQueue', array('getQueue'), array($this->mockedConnection, $this->queue));
		$queue->setContainer($this->mockedContainer);
		$queue->expects($this->any())->method('getQueue')->with($this->queue)->will($this->returnValue($this->queue));
		$this->mockedChannel->shouldReceive('queue_declare')->once()->with($this->queue, false, true, false, false)->andReturn(null);
		$this->message->delivery_info = array();
		$this->mockedChannel->shouldReceive('basic_publish')->once()->andReturn(null);
		$result = $queue->push($this->job, $this->data, $this->queue);
		$this->assertInstanceOf('Illuminate\Http\Response', $result);
	}

	public function testPopProperlyPopsJobOffOfSqs()
	{
		$this->mockedConnection->shouldReceive('channel')->once()->andReturn($this->mockedChannel);
		$queue = $this->getMock('Illuminate\Queue\RabbitQueue', array('getQueue'), array($this->mockedConnection, $this->queue));
		$queue->setContainer($this->mockedContainer);
		$queue->expects($this->any())->method('getQueue')->with($this->queue)->will($this->returnValue($this->queue));
		$this->mockedChannel->shouldReceive('queue_declare')->once()->with($this->queue, false, true, false, false)->andReturn(null);
		$this->mockedChannel->shouldReceive('basic_get')->once()->with($this->queue)->andReturn($this->message);
		//$this->mockedChannel->shouldReceive('basic_ack')->once()->with($this->message->delivery_info['delivery_tag'])->andReturn(null);
		$result = $queue->pop($this->queue);
		$this->assertInstanceOf('Illuminate\Queue\Jobs\RabbitJob', $result);
	}

	/**
	 * @expectedException RuntimeException
	 */
	public function testDelayedPushThrowsRuntimeException()
	{
		$this->mockedConnection->shouldReceive('channel')->once()->andReturn($this->mockedChannel);
		$queue = $this->getMock('Illuminate\Queue\RabbitQueue', array('getQueue'), array($this->mockedConnection, $this->queue));
		$queue->setContainer($this->mockedContainer);
		$queue->expects($this->any())->method('getQueue')->with($this->queue)->will($this->returnValue($this->queue));
		$queue->later($this->delay, $this->job, $this->data, $this->queue);
	}

}
