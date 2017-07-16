<?php
namespace AmpReactor\Test\Operator;
use AmpReactor\InteractiveProducer;

class WindowTest extends \AmpReactor\Test\OperatorTestCase {
	public function test_dense_windows() {
		$signal = new \Amp\Producer((function($emitter) {
			while(true) {
				yield \AmpReactor\Util\defer();
				$emitter(true); // whoa, _really_ dense
			}
		}));
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			yield $this->map_defer(range(0, 10), $emitter);
		});
		
		$this->assertHotColdConsumersSeeValues(
			range(0, 10),
			$producer->window($signal)
			         ->flat_map()
		);
	}
	
	public function test_sparse_windows() {
		$signal = new \Amp\Producer((function($emitter) {
			while(true) {
				yield \AmpReactor\Util\defer();
				yield \AmpReactor\Util\defer();
				yield \AmpReactor\Util\defer();
				$emitter(true);
			}
		}));
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			yield $this->map_defer(range(0, 10), $emitter);
		});
		
		$this->assertHotColdConsumersSeeValues(
			range(0, 10),
			$producer->window($signal)
			         ->flat_map()
		);
	}
}