<?php namespace Dcarrith\Queuel\Jobs;

use DateTime;
use Illuminate\Queue\Jobs\Job;
//use Log;

abstract class QueuelJob extends Job {

	/**
	 * Get the IoC container instance.
	 *
	 * @return \Illuminate\Container\Container
	 */
	public function getContainer()
	{
		return $this->container;
	}

}
