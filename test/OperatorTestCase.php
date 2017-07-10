<?php
namespace AmpReactor\Test;

use AmpReactor\Producer;

use Amp\Promise;
use Amp\Coroutine;
use function Amp\Promise\wait;
use function Amp\Promise\all;
class OperatorTestCase extends \PHPUnit\Framework\TestCase {
	private static function direct_collapse(Producer $producer): Promise {
		return new Coroutine((function() use ($producer) {
			$values = [];
			while(yield $producer->advance())
				$values[] = $producer->getCurrent();
			
			return $values;
		})());
	}
	public function assertHotColdConsumersSeeValues(array $expected, Producer $producer) {
		$results = wait(
			all([
				self::direct_collapse(clone $producer),
				self::direct_collapse($producer),
				self::direct_collapse($producer)
			])
		); // tuple<ColdValues, HotValues, HotValues>, for ColdValues := HotValues := array<TExpected>
		// extended argument set for $canonicalize = true: order doesn't need to match
		$this->assertEquals($expected, $results[0], 'Cold consumer', 0.0, 10, true);
		$this->assertEquals($expected, array_merge($results[1], $results[2]), 'Hot consumers', 0.0, 10, true);
	}
}