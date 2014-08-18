<?php namespace Dcarrith\Queuel;

abstract class PushQueue extends Queuel implements PushQueueInterface {

	/**
	* The current request instance.
	*
	* @var \Illuminate\Http\Request
	*/
	protected $request;

	/**
	* Get the request instance.
	*
	* @return \Symfony\Component\HttpFoundation\Request
	*/
	public function getRequest()
	{
		return $this->request;
	}

	/**
	* Set the current request instance.
	*
	* @param  \Symfony\Component\HttpFoundation\Request  $request
	* @return void
	*/
	public function setRequest(Request $request)
	{
		$this->request = $request;
	}

}
