<?php
namespace AmpReactor\Test\Operator;
use AmpReactor\InteractiveProducer;
class FlatMapTest extends \AmpReactor\Test\OperatorTestCase {
	private static function iterable_to_producerish($iterable): callable {
		return function($emitter) use ($iterable) {
			if(!$iterable instanceof Traversable && !is_array($iterable))
				throw new \InvalidArgumentException('$iterable is not Traversable nor array');
			
			yield self::map_defer($iterable, function($item) use ($emitter) {
				$emitter($item);
			});
		};
	}
	
	private static function nested_iterable_to_producerish($iterable): callable {
		return function($emitter) use ($iterable) {
			if(!$iterable instanceof Traversable && !is_array($iterable))
				throw new \InvalidArgumentException('$iterable is not Traversable nor array');
			
			yield self::map_defer($iterable, function($item) use ($emitter) {
				$emitter(self::iterable_to_producerish($item));
			});
		};
	}
	
	public function test_short_sequences() {
		$nested_sequence = [ [ 1, 2 ], [ 3, 4 ] ];
		$producerish = self::nested_iterable_to_producerish($nested_sequence);
		$this->assertHotColdConsumersSeeValues(
			[ 1, 2, 3, 4 ],
			InteractiveProducer::from_producerish($producerish)
			                   ->flat_map(function($I) { return $I; })
		);
	}
	
	public function test_long_head() {
		$nested_sequence = [ range(1, 100), [ 101, 102 ] ];
		$producerish = self::nested_iterable_to_producerish($nested_sequence);
		$this->assertHotColdConsumersSeeValues(
			range(1, 102),
			InteractiveProducer::from_producerish($producerish)
			                   ->flat_map(function($I) { return $I; })
		);
	}
	
	public function test_long_tail() {
		$nested_sequence = [ [ 1, 2 ], range(3, 102) ];
		$producerish = self::nested_iterable_to_producerish($nested_sequence);
		$this->assertHotColdConsumersSeeValues(
			range(1, 102),
			InteractiveProducer::from_producerish($producerish)
			                   ->flat_map(function($I) { return $I; })
		);
	}
	
	public function test_many_short_sequences() {
		$source = range(1, 100);
		$nested_sequence = array_chunk($source, 3);
		$producerish = self::nested_iterable_to_producerish($nested_sequence);
		$this->assertHotColdConsumersSeeValues(
			range(1, 100),
			InteractiveProducer::from_producerish($producerish)
			                   ->flat_map(function($I) { return $I; })
		);
	}
	
	public function test_many_longer_sequences() {
		$source = range(1, 1000);
		$nested_sequence = array_chunk($source, 33);
		$producerish = self::nested_iterable_to_producerish($nested_sequence);
		$this->assertHotColdConsumersSeeValues(
			range(1, 1000),
			InteractiveProducer::from_producerish($producerish)
			                   ->flat_map(function($I) { return $I; })
		);
	}
	
	public function test_non_identity_mapper() {
		$source = range(1, 10);
		$nested_sequence = array_chunk($source, 3);
		$producerish = self::iterable_to_producerish($nested_sequence);
		$this->assertHotColdConsumersSeeValues(
			range(1, 10),
			InteractiveProducer::from_producerish($producerish)
		                      ->flat_map(function($sequence) {
		   	return function($emitter) use ($sequence) {
	   			return self::iterable_to_producerish($sequence)($emitter);
	   		};
		   })
		);
	}
}