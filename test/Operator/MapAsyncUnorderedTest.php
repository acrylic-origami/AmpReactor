<?php
namespace AmpReactor\Test\Operator;
use AmpReactor\InteractiveProducer;

class MapAsyncUnorderedTest extends \AmpReactor\Test\OperatorTestCase {
	public function test_identity() {
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			yield $this->map_defer(range(0, 2), $emitter);
		});
		$this->assertHotColdConsumersSeeValues(
			range(0, 2),
			$producer->map_async_unordered()
		);
	}
	public function test_non_identity() {
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			yield $this->map_defer(range(0, 2), $emitter);
		});
		$this->assertHotColdConsumersSeeValues(
			[0, 1, 4],
			$producer->map_async_unordered(function($i) { return pow($i, 2); })
		);
	}
	
	// THIS IS A RACEY TEST
	// It relies on timing accuracy and the speed of synchronous parts of the program to enforce ordering
	// If it fails but all elements are present, it could be due to non-guaranteed ordering within the scheduler.
	public function test_nonblocking() {
		$delays = [30, 20, 10]; // order is purposeful
		$producer = InteractiveProducer::from_producerish(function($emitter) use ($delays) {
			$delay_futures = array_map(function($delay) { return new \Amp\Delayed($delay, $delay);
			}, $delays);
			foreach($delay_futures as $future) 
				$emitter($future);
			
			yield \Amp\Promise\all($delay_futures);
		});
		$this->assertHotColdConsumersSeeOrderedValues(
			[10, 20, 30],
			$producer->map_async_unordered()
		);
	}
}