<?php
namespace AmpReactor\Test\Operator;
use AmpReactor\InteractiveProducer;
class GroupByTest extends \AmpReactor\Test\OperatorTestCase {
	public function test_unique_identity_keys() {
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			for($i = 0; $i < 4; $i++)
				$emitter($i);
			if(false) yield null;
		});
		$this->assertHotColdConsumersSeeValues(
			[[ 0 ], [ 1 ], [ 2 ], [ 3 ]],
			$producer->group_by(function($I) { return $I; })
			         
		);
	}
	
	public function test_sparse_keys() {
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			for($i = 0; $i < 10; $i++)
				$emitter($i);
			if(false) yield null;
		});
		$this->assertHotColdConsumersSeeValues(
			[range(0, 8, 2), range(1, 9, 2)],
			$producer->group_by(function($I) { return $I % 2; })
		);
	}
	
	public function test_deferred_items() {
		$producer = InteractiveProducer::from_producerish(function($emitter) {
			yield $this->map_defer(range(0, 3), $emitter);
		});
		$this->assertHotColdConsumersSeeValues(
			[[ 0 ], [ 1 ], [ 2 ], [ 3 ]],
			$producer->group_by(function($I) { return $I; })
		);
	}
}