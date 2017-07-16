<?php
namespace AmpReactor\Test;

use AmpReactor\InteractiveProducer;

use Amp\Promise;
use Amp\Iterator;
use Amp\Coroutine;
use function Amp\Promise\wait;
use function Amp\Promise\all;
class OperatorTestCase extends \PHPUnit\Framework\TestCase {
	protected static function map_defer(array $iterable, callable $action): \Amp\Promise {
		$item = current($iterable);
		$action($item);
		if(next($iterable) !== FALSE)
			return \AmpReactor\Util\defer(function() use ($iterable, $action) {
				return self::map_defer($iterable, $action);
			});
		else
			return new \Amp\Success();
	}
	
	private static function direct_collapse(\Amp\Iterator $producer): Promise {
		return new Coroutine((function() use ($producer) {
			$values = [];
			while(yield $producer->advance()) {
				$producer_or_value = $producer->getCurrent();
				/* [TEMP] */
				if($producer_or_value instanceof InteractiveProducer)
					$values[] = yield self::direct_collapse(clone $producer_or_value);
				/* [/TEMP] */
				elseif($producer_or_value instanceof \Amp\Iterator)
					$values[] = yield self::direct_collapse($producer_or_value);
				else
					$values[] = $producer_or_value;
			}
			
			return $values;
		})());
	}	
	
	protected function assertHotColdConsumersSeeValues(array $expected, InteractiveProducer $producer) {
		$results = wait(
			all([
				self::direct_collapse(clone $producer),
				self::direct_collapse($producer),
				self::direct_collapse($producer)
			])
		); // tuple<ColdValues, HotValues, HotValues>, for ColdValues := HotValues := array<TExpected>
		// extended argument set for $canonicalize = true: order doesn't need to match
		// var_dump($results);
		$this->assertEquals($expected, $results[0], 'Cold consumer', 0.0, 10, true);
		$this->assertEquals($expected, array_merge($results[1], $results[2]), 'Hot consumers', 0.0, 10, true);
	}
}