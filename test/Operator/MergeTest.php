<?php
namespace AmpReactor\Test\Operator;

use AmpReactor\InteractiveProducer;

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
			\AmpReactor\InteractiveProducer::merge([ $left, $right ])
		);
	}
	public function test_deferred_values() {
		$left = new \Amp\Producer(function($emitter) {
			$deferred = new \Amp\Deferred();
			\Amp\Loop::defer(function() use ($emitter, $deferred) {
				$emitter(2);
				$deferred->resolve();
			});
			$emitter(4);
			$emitter(6);
			yield $deferred;
		});
		$right = new \Amp\Producer(function($emitter) {
			$deferred = new \Amp\Deferred();
			$emitter('foo');
			\Amp\Loop::defer(function() use ($emitter, $deferred) {
				$emitter('bar');
				\Amp\Loop::defer(function() use ($emitter, $deferred) {
					$emitter('quux');
					$deferred->resolve();
				});
			});
			$emitter('baz');
			yield $deferred;
		});
		$this->assertHotColdConsumersSeeValues(
			[2, 4, 6, 'foo', 'bar', 'baz', 'quux'],
			InteractiveProducer::merge([ $left, $right ])
		);
	}
}