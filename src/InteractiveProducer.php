<?php

namespace AmpReactor;

use AmpReactor\Internal\Pointer;
use AmpReactor\Internal\Queue;
use Amp\Iterator;
use Amp\Coroutine;

final class InteractiveProducer extends Internal\Producer implements Iterator {
	use \Amp\CallableMaker, Operators;
	
	/**
	 * @var Pointer<Pointer<bool>>
	 */
	protected $some_running;

	/**
	 * @param callable(callable(mixed $value): Promise $emit): array<Iterator | \Generator> $producer
	 *
	 * @throws \Error Thrown if the callable does not return a Generator.
	 */
	protected function __construct(callable $producer) {
		parent::__construct();
		$this->some_running = new Pointer(new Pointer(false));
		
		$iterators = $producer($this->callableFromInstanceMethod("emit"));
		
		if (!is_array($iterators)) {
			throw new \Error("The callable did not return an array");
		}
		
		$coroutines = [];
		$emitter = $this->callableFromInstanceMethod("emit");
		foreach($iterators as $iterator) {
			if(!$iterator instanceof Iterator && !$iterator instanceof \Generator)
				throw \Amp\Internal\createTypeError([Iterator::class, \Generator::class], $iterator);
			
			$this->iterators[] = $iterator;
			if($iterator instanceof Iterator)
				$iterator = $this->iterator_to_emitting_generator($iterator, $emitter); // coerce to generator
			$coroutines[] = new Coroutine($iterator);
		}
		$lifetime = \Amp\Promise\all($coroutines);
		$lifetime->onResolve(function ($exception) {
			if ($this->complete->value) {
				return;
			}

			if ($exception) {
				$this->fail($exception);
				return;
			}

			$this->complete();
		});
	}
	public function __destruct() {
		$this->_detach();
	}

	protected function _detach() {
		if($this->this_running) {
			$this->running_count->value--;
			if($this->running_count->value === 0) {
				$this->some_running->value->value = false;
			}
		}
	}
	private function iterator_to_emitting_generator(Iterator $iterator, callable $emitter): \Generator {
		// `HHReactor\Producer::awaitify`'s Amp twin
		// eradicate all references to `$this` from the generator
		$stashed_some_running = $this->some_running->value;
		return (function() use ($stashed_some_running, $iterator, $emitter) {
			while(yield $iterator->advance()) {
				if(true === $stashed_some_running->value)
					$emitter($iterator->getCurrent());
				else
					yield $emitter($iterator->getCurrent());
			}
		})();
	}
	
	public static function create(Iterator $iterator): InteractiveProducer {
		return new self(function(callable $_) use ($iterator) {
			return [$iterator];
		});
	}
	
	public static function from_producerish(callable $producerish): InteractiveProducer {
		return new self(function(callable $emitter) use ($producerish) {
			return [$producerish($emitter)];
		});
	}
}