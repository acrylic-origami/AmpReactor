<?php
namespace AmpReactor\Test\Operator;
class MergeTest extends \AmpReactor\Test\OperatorTestCase {
	public function test_ready_values() {
		$left = new \Amp\Producer(function($emitter) {
			$emitter(2);
			$emitter(4);
			$emitter(6);
			if(false) yield null;
		});
		$right = new \Amp\Producer(function($emitter) {
			$emitter('foo');
			$emitter('bar');
			$emitter('baz');
			$emitter('quux');
			if(false) yield null;
		});
		$this->assertHotColdConsumersSeeValues(
			[2, 4, 6, 'foo', 'bar', 'baz', 'quux'],
			\AmpReactor\Producer::merge([ $left, $right ])
		);
	}
}