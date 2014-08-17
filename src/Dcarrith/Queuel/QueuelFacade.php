<?php namespace Dcarrith\Queuel;

use Illuminate\Support\Facades\Facade;

class QueuelFacade extends Facade {

        /**
         * Get the registered name of the component.
         *
         * @return string
         */
        protected static function getFacadeAccessor() { return 'queuel'; }
}
