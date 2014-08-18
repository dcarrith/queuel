<?php

use Mockery as m;

class QueueIronQueueTest extends PHPUnit_Framework_TestCase {

	public function tearDown()
	{
		m::close();
	}

	public function testPushProperlyPushesJobOntoIron()
	{
		$queue = new Dcarrith\Queuel\IronQueue($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), m::mock('Illuminate\Http\Request'), 'default', true);
		$crypt->shouldReceive('encrypt')->once()->with(json_encode(array('job' => 'foo', 'data' => array(1, 2, 3), 'attempts' => 1, 'queue' => 'default')))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', array())->andReturn((object) array('id' => 1));
		$queue->push('foo', array(1, 2, 3));
	}

	public function testPushProperlyPushesJobOntoIronWithoutEncryption()
	{
		$queue = new Dcarrith\Queuel\IronQueue($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), m::mock('Illuminate\Http\Request'), 'default');
		$crypt->shouldReceive('encrypt')->never();
		$iron->shouldReceive('postMessage')->once()->with('default', json_encode(['job' => 'foo', 'data' => [1, 2, 3], 'attempts' => 1, 'queue' => 'default']), array())->andReturn((object) array('id' => 1));
		$queue->push('foo', array(1, 2, 3));
	}

	public function testPushProperlyPushesJobOntoIronWithClosures()
	{
		$queue = new Dcarrith\Queuel\IronQueue($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), m::mock('Illuminate\Http\Request'), 'default', true);
		$queue->setEncrypter($crypt);
		$name = 'Foo';
		$closure = new Illuminate\Support\SerializableClosure($innerClosure = function() use ($name) { return $name; });
		$crypt->shouldReceive('encrypt')->once()->with(serialize($closure))->andReturn('serial_closure');
		$crypt->shouldReceive('encrypt')->once()->with(json_encode(array(
			'job' => 'IlluminateQueueClosure', 'data' => array('closure' => 'serial_closure'), 'attempts' => 1, 'queue' => 'default'
		)))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', array())->andReturn((object) array('id' => 1));
		$queue->push($innerClosure);
	}

	public function testDelayedPushProperlyPushesJobOntoIron()
	{
		$queue = new Dcarrith\Queuel\IronQueue($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), m::mock('Illuminate\Http\Request'), 'default', true);
		$crypt->shouldReceive('encrypt')->once()->with(json_encode(array(
			'job' => 'foo', 'data' => array(1, 2, 3), 'attempts' => 1, 'queue' => 'default',
		)))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', array('delay' => 5))->andReturn((object) array('id' => 1));
		$queue->later(5, 'foo', array(1, 2, 3));
	}

	public function testDelayedPushProperlyPushesJobOntoIronWithTimestamp()
	{
		$now = Carbon\Carbon::now();
		$queue = $this->getMock('Dcarrith\Queuel\IronQueue', array('getTime'), array($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), m::mock('Illuminate\Http\Request'), 'default', true));
		$queue->expects($this->once())->method('getTime')->will($this->returnValue($now->getTimestamp()));
		$crypt->shouldReceive('encrypt')->once()->with(json_encode(array('job' => 'foo', 'data' => array(1, 2, 3), 'attempts' => 1, 'queue' => 'default')))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', array('delay' => 5))->andReturn((object) array('id' => 1));
		$queue->later($now->addSeconds(5), 'foo', array(1, 2, 3));
	}

	public function testPopProperlyPopsJobOffOfIron()
	{
		$queue = new Dcarrith\Queuel\IronQueue($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), m::mock('Illuminate\Http\Request'), 'default', true);
		$queue->setContainer(m::mock('Illuminate\Container\Container'));
		$iron->shouldReceive('getMessage')->once()->with('default')->andReturn($job = m::mock('IronMQ_Message'));
		$job->body = 'foo';
		$crypt->shouldReceive('decrypt')->once()->with('foo')->andReturn('foo');
		$result = $queue->pop();

		$this->assertInstanceOf('Dcarrith\Queuel\Jobs\IronJob', $result);
	}

	public function testPopProperlyPopsJobOffOfIronWithoutEncryption()
	{
		$queue = new Dcarrith\Queuel\IronQueue($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), m::mock('Illuminate\Http\Request'), 'default');
		$queue->setContainer(m::mock('Illuminate\Container\Container'));
		$iron->shouldReceive('getMessage')->once()->with('default')->andReturn($job = m::mock('IronMQ_Message'));
		$job->body = 'foo';
		$crypt->shouldReceive('decrypt')->never();
		$result = $queue->pop();

		$this->assertInstanceOf('Dcarrith\Queuel\Jobs\IronJob', $result);
	}

	public function testPushedJobsCanBeMarshaled()
	{
		$queue = $this->getMock('Dcarrith\Queuel\IronQueue', array('createIronJob'), array($iron = m::mock('IronMQ'), $crypt = m::mock('Illuminate\Encryption\Encrypter'), $request = m::mock('Illuminate\Http\Request'), 'default', true));
		$request->shouldReceive('header')->once()->with('iron-message-id')->andReturn('message-id');
		$request->shouldReceive('getContent')->once()->andReturn($content = json_encode(array('job' => 'foo', 'data' => array(1, 2, 3), 'attempts' => 1, 'queue' => 'default')));
		$crypt->shouldReceive('decrypt')->once()->with($content)->andReturn($content);
		$pushedJob = (object) array('id' => 'message-id', 'body' => $content, 'pushed' => true);
		$queue->expects($this->once())->method('createIronJob')->with($this->equalTo($pushedJob), true)->will($this->returnValue($mockedIronJob = m::mock('StdClass')));
		$mockedIronJob->shouldReceive('fire')->once();
		$response = $queue->marshal();
		$this->assertInstanceOf('Illuminate\Http\Response', $response);
		$this->assertEquals(200, $response->getStatusCode());
	}

}
