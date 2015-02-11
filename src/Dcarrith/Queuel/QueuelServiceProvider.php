<?php namespace Dcarrith\Queuel;

//use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\QueueServiceProvider;
use Dcarrith\Queuel\Console\WorkCommand;
use Dcarrith\Queuel\Console\ListenCommand;
use Dcarrith\Queuel\Console\SubscribeCommand;
use Dcarrith\Queuel\Console\UnsubscribeCommand;
use Dcarrith\Queuel\Console\UpdateCommand;
use Dcarrith\Queuel\Connectors\IronConnector;
use Dcarrith\Queuel\Connectors\RabbitConnector;
use Dcarrith\Queuel\Connectors\SqsConnector;
//use Log;

/*
use IlluminateQueueClosure;
use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Console\ListenCommand;
use Illuminate\Queue\Console\RestartCommand;
use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Queue\Console\SubscribeCommand;
use Illuminate\Queue\Connectors\SyncConnector;
use Illuminate\Queue\Connectors\IronConnector;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Queue\Connectors\BeanstalkdConnector;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
*/

class QueuelServiceProvider extends QueueServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		//$this->package('dcarrith/queuel');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		parent::register();

		$this->registerManager();

		$this->registerWorker();

		$this->registerListener();

                $this->registerSubscriber();

                $this->registerUnsubscriber();

                $this->registerUpdater();
	}

	/**
	 * Register the queue manager.
	 *
	 * @return void
	 */
	protected function registerManager()
	{
		$this->app->bindShared('queuel', function($app)
		{
			// Once we have an instance of the queue manager, we will register the various
			// resolvers for the queue connectors. These connectors are responsible for
			// creating the classes that accept queue configs and instantiate queues.
			$manager = new QueueManager($app);

			$this->registerConnectors($manager);

			return $manager;
		});
	}

	/**
	 * Register the queue worker.
	 *
	 * @return void
	 */
	protected function registerWorker()
	{
		$this->registerWorkCommand();

		$this->registerRestartCommand();

		$this->app->bindShared('queuel.worker', function($app)
		{
			return new Worker($app['queuel'], $app['queue.failer'], $app['events']);
		});
	}

	/**
	 * Register the queue worker console command.
	 *
	 * @return void
	 */
	protected function registerWorkCommand()
	{
		$this->app->bindShared('command.queue.work', function($app)
		{
			return new WorkCommand($app['queuel.worker']);
		});

		$this->commands('command.queue.work');
	}

	/**
	 * Register the queue listener.
	 *
	 * @return void
	 */
	protected function registerListener()
	{
		$this->registerListenCommand();

		$this->app->bindShared('queuel.listener', function($app)
		{
			return new Listener($app['path.base']);
		});
	}

	/**
	 * Register the queue listener console command.
	 *
	 * @return void
	 */
	protected function registerListenCommand()
	{
		$this->app->bindShared('command.queue.listen', function($app)
		{
			return new ListenCommand($app['queuel.listener']);
		});

		$this->commands('command.queue.listen');
	}

        /**
         * Register the connectors on the queue manager.
         *
         * @param  \Illuminate\Queue\QueueManager  $manager
         * @return void
         */
        public function registerConnectors($manager)
        {
		//parent::registerConnectors($manager);

                foreach (array('Sqs', 'Rabbit', 'Iron') as $connector)
                {
                        $this->{"register{$connector}Connector"}($manager);
                }
        }

        /**
         * Register the push queue subscribe command.
         *
         * @return void
         */
        protected function registerSubscriber()
        {
                $this->app->bindShared('command.queue.subscribe', function($app)
                {
                        return new SubscribeCommand;
                });

                $this->commands('command.queue.subscribe');
        }

        /**
         * Register the push queue unsubscribe command.
         *
         * @return void
         */
        protected function registerUnsubscriber()
        {
                $this->app->bindShared('command.queue.unsubscribe', function($app)
                {
                        return new UnsubscribeCommand;
                });

                $this->commands('command.queue.unsubscribe');
        }

        /**
         * Register the push queue update command.
         *
         * @return void
         */
        protected function registerUpdater()
        {
                $this->app->bindShared('command.queue.update', function($app)
                {
                        return new UpdateCommand;
                });

                $this->commands('command.queue.update');
        }

	/**
	 * Register the RabbitMQ queue connector.
	 *
	 * @param  \Illuminate\Queue\QueueManager  $manager
	 * @return void
	 */
	protected function registerRabbitConnector($manager)
	{
		$app = $this->app;

		//Log::info('QueuelServiceProvider registerRabbitConnector', array('connector'=>'rabbit'));

		$manager->addConnector('rabbit', function() use ($app)
		{
			return new RabbitConnector($app['request']);
		});

		$this->registerRequestBinder('rabbit');
	}

        /**
         * Register the Amazon SQS queue connector.
         *
         * @param  \Illuminate\Queue\QueueManager  $manager
         * @return void
         */
        protected function registerSqsConnector($manager)
        {
                $app = $this->app;

                //Log::info('QueuelServiceProvider registerSqsConnector', array('connector'=>'sqs'));

                $manager->addConnector('sqs', function() use ($app)
                {
                        return new SqsConnector($app['request']);
                });

                $this->registerRequestBinder('sqs');
        }

	/**
	 * Register the IronMQ queue connector.
	 *
	 * @param  \Illuminate\Queue\QueueManager  $manager
	 * @return void
	 */
	protected function registerIronConnector($manager)
	{
		$app = $this->app;

		$manager->addConnector('iron', function() use ($app)
		{
			return new IronConnector($app['encrypter'], $app['request']);
		});

		$this->registerRequestBinder('iron');
	}

	/**
	 * Register the request rebinding event for the push queue.
	 *
	 * @param string $driver
	 * @return void
	 */
	protected function registerRequestBinder($driver)
	{
		$this->app->rebinding('request', function($app, $request) use ($driver)
		{
			if ($app['queuel']->connected($driver))
			{
				$app['queuel']->connection($driver)->setRequest($request);
			}
		});
	}

        /**
         * Get the services provided by the provider.
         *
         * @return array
         */
        public function provides()
        {
		return array(
			'queue', 'queuel.worker', 'queuel.listener',
			'command.queue.work', 'command.queue.listen',
			'command.queue.subscribe'
		);
        }

}
