<?php
namespace AmpReactor\Test\Operator;
use AmpReactor\InteractiveProducer;

class DebounceTest extends \AmpReactor\Test\OperatorTestCase {
	public function test_ready_items_only_emits_last() {
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			yield $this->map_defer(range(0, 2), $emitter);
		});
		$this->assertHotColdConsumersSeeValues(
			[2],
			$producer->debounce(10)
		);
	}
	
	public function test_emit_some() {
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			$emitter(1);
			$emitter(2);
			yield \AmpReactor\Util\defer();
			$emitter(3);
			$emitter(yield new \Amp\Delayed(10, 4));
			$emitter(yield new \Amp\Delayed(20, 5));
			$emitter(yield new \Amp\Delayed(20, 6));
			$emitter(yield new \Amp\Delayed(10, 7));
			$emitter(yield new \Amp\Delayed(20, 8));
		});
		$this->assertHotColdConsumersSeeValues(
			[4, 5, 7, 8],
			$producer->debounce(15)
		);
	}
}