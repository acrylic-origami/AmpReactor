<?php
namespace AmpReactor\Test\Operator;

use AmpReactor\InteractiveProducer;

class ZipTest extends \AmpReactor\Test\OperatorTestCase {
	public function test_ready_values() {
		$left = new \Amp\Producer(function($emitter) {
			$emitter('PB');
			$emitter('Left ');
			$emitter('K');
			if(false) yield null;
		});
		$right = new \Amp\Producer(function($emitter) {
			$emitter('J');
			$emitter(' Right');
			$emitter('R');
			if(false) yield null;
		});
		$combiner = function($left, $right) { return $left . '&' . $right; };
		$this->assertHotColdConsumersSeeValues(
			['PB&J', 'Left & Right', 'K&R'],
			InteractiveProducer::zip($left, $right, $combiner)
		);
	}
	public function test_deferred_values() {
		$left = new \Amp\Producer(function($emitter) {
			$emitter('PB');
			$emitter('Left ');
			yield \AmpReactor\Util\defer();
			yield \AmpReactor\Util\defer();
			$emitter('K');
		});
		$right = new \Amp\Producer(function($emitter) {
			$emitter('J');
			yield \AmpReactor\Util\defer();
			$emitter(' Right');
			yield \AmpReactor\Util\defer();
			$emitter('R');
		});
		$combiner = function($left, $right) { return $left . '&' . $right; };
		$this->assertHotColdConsumersSeeValues(
			['PB&J', 'Left & Right', 'K&R'],
			InteractiveProducer::zip($left, $right, $combiner)
		);
	}
}