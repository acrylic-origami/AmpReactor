<?php
namespace AmpReactor\Util;
use \Amp\Deferred;
use \Amp\Promise;
use \Amp\Loop;
function defer(callable $to_defer = null): Promise {
	$deferred = new Deferred;
	Loop::defer(function() use ($deferred, $to_defer) {
		if(!is_null($to_defer))
			$deferred->resolve($to_defer());
		else
			$deferred->resolve();
	});
	return $deferred->promise();
}