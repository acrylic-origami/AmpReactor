<?php
namespace AmpReactor;
use Amp\Success;
use Amp\Coroutine;
use Amp\Iterator;
use Amp\Promise;
class Producer extends BaseProducer {
	/**
	 * @var Pointer<array<Iterator | Generator>> $iterators
	 */
	protected $iterators;
	
	/**
	 * @var ?\Amp\Producer<T>
	 */
	protected $_producer = null;
	
	/**
	 * @param (function((function(T | Promise<T>): Promise<void | Promise<void>>)): array<Iterator | Generator>) $coroutines_factory
	 */
	protected function __construct(callable $iterator_factory) {
		$this->running_count = new Pointer(0);
		$this->some_running = new Pointer(new Pointer(false));
		
		$this->buffer = new Queue([null]);
		$this->iterators = $iterators = []; // stash to eradicate references to `$this` in the `\Amp\Producer` generator
		$this->_producer = new \Amp\Producer(function(callable $emitter) use ($iterator_factory, &$iterators) {
			$coroutines = [];
			$iterators = $iterator_factory($emitter);
			foreach($iterators as $iterator) {
				// $proxy = function($value) use ($emitter) {
				// 	var_dump($value);
				// 	return $emitter($value);
				// };
				$iterators[] = $iterator;
				if($iterator instanceof Iterator)
					$iterator = $this->iterator_to_emitting_generator($iterator, $emitter); // coerce to generator
				$coroutines[] = new Coroutine($iterator);
			}
			yield \Amp\Promise\all($coroutines);
		});
	}
	
	protected function _attach() {}
	
	/* <<__Override>> */
	protected function _detach() {
		parent::_detach();
		
		if(false === $this->some_running->value->value)
			foreach($this->iterators as $iterator)
				if($iterator instanceof BaseProducer)
					$iterator->_detach();
	}
	
	/**
	 * @param Iterator<T> $iterator
	 * @param (function(T | Promise<T>): Promise<void | Promise<void>>) $emitter Provided by \Amp\Producer
	 * 
	 * @return Generator<mixed, Promise<bool>, bool>
	 */
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
	public static function create(Iterator $iterator): Producer {
		return new self(function(callable $_) use ($iterator) {
			return [$iterator];
		});
	}
	
	// BaseProducer takes care of the flags and some buffering checks in `advance`; just push the iterator here if we don't have any items to emit
	protected function _produce(): Promise {
		if(!$this->buffer->is_empty())
			return new Success(true);
		else
			return $this->_producer->advance();
	}
	public function getCurrent() {
		if($this->buffer->is_empty())
			$this->buffer->add($this->_producer->getCurrent());
		
		return $this->buffer->peek();
	}
	
	/**
	 * [Combine multiple Producers into one by merging their emissions](http://reactivex.io/documentation/operators/merge.html)
	 * @param array<Iterator> $iterators
	 * @return Producer
	 */
	public static function merge(array $iterators): Producer {
		return new self(function($_) use ($iterators) {
			return array_map(function(Iterator $iterator) {
				if($iterator instanceof BaseProducer)
					$iterator = clone $iterator;
				else
					return $iterator;
			}, $iterators);
		});
	}
	
	/**
	 * [Transform the items emitted by a Producer into Producers, then flatten the emissions from those into a single Producer](http://reactivex.io/documentation/operators/flatmap.html)
	 * 
	 * **Spec**:
	 *   - `Tv`-typed items from the return value might not preserve the order they are produced in _separate_ Producers created by `$coercer`.
	 * - **Preferred**:
	 *   - All ordering must be preserved.
	 * @param (function(T): Producer<Tv>) $coercer - Transform `T`-valued items to Producers. E.g. for `T := Producer<Tv>`, $coercer may just be the identity function.
	 */
	public function flat_map(callable $coercer): Producer {
		$clone = clone $this;
		return new self(function(callable $emitter) {
			return [new Coroutine((function() use ($emitter, $clone) {
				while(yield $clone->advance()) {
					$emitter((function() use ($emitter) {
						$subclone = clone $coercer($clone->getCurrent());
						while(yield $subclone->advance())
							$emitter($subclone->getCurrent());
					})());
				}
			})())];
		});
	}
	
	/**
	 * [Divide a Producer into a set of Producers that each emit a different subset of items from the original Producer.](http://reactivex.io/documentation/operators/groupby.html)
	 * 
	 * **Spec**:
	 * - Any items produced after the beginning call in the original Producer must be produced by exactly one of the `this`-typed Producers in the return value.
	 * @param <Tk as arraykey>(function(T): Tk) $keysmith - Assign `Tk`-valued keys to `T`-valued items
	 */
	public function group_by(callable $keysmith): Producer {
		$subjects = [];
		$clone = clone $this;
		return self::create(new \Amp\Producer(function($emitter) use (&$subjects, $clone) {
			while(yield $clone->advance()) {
				$value = $clone->getCurrent();
				$key = $keysmith($value);
				if(!array_key_exists($key, $subjects)) {
					$subject = new Emitter();
					$subjects[$key] = $subject;
					$emitter($subject->iterate());
				}
				$subjects[$key]->emit($value);
			}
			foreach($subjects as $subject)
				$subject->complete();
		}));
	}
	
	/**
	 * [Only emit an item from a Producer if a particular timespan has passed without it emitting another item](http://reactivex.io/documentation/operators/debounce.html)
	 * 
	 * **Spec**
	 * - The last value of the original Producer, if there is one, must be produced in the return value.
	 * @param int $timespan - The "timespan" as described above, in milliseconds.
	 */
	public function debounce(int $timespan): Producer {
		$clone = clone $this;
		return new self(function($outer_emitter) use ($timespan, $clone) {
			$counter = 0;
			$time_shifted_stream = new \Amp\Producer(function($inner_emitter) use (&$counter, $timespan, $clone) {
				while(yield $clone->advance())
					$inner_emitter(new Delayed($timespan, new class(++$counter, $clone->getCurrent()) {
						use \Amp\Struct;
						public $stashed_counter;
						public $payload;
						
						public function __construct($counter, $payload) {
							$this->stashed_counter = $counter;
							$this->payload = $payload;
						}
					}));
			});
			while(yield $time_shifted_stream->advance()) {
				$item = $time_shifted_stream->getCurrent();
				if($item->stashed_counter === $counter)
					$outer_emitter($item->payload);
			}
		});
	}
	
	/**
	 * [periodically subdivide items from a Producer into Producer windows and emit these windows rather than emitting the items one at a time](http://reactivex.io/documentation/operators/window.html)
	 * 
	 * Note: if the `$signal` ends prematurely (before the end of the source `Producer`), the items continue to be produced on the last window.
	 * @param \Amp\Iterator $signal - Produce a value whenever a new window opens.
	 * @return Producer Produce Producers that group values from the original into windows dictated by `$signal`.
	 */
	public function window(Iterator $signal): Producer {
		$clone = clone $this;
		return new self(function($emitter) use ($clone, $signal) {
			$subject = new Emitter();
			$finished = false;
			return [
				(function() use (&$subject, $clone, &$finished) {
					while(yield $clone->advance())
						$subject->emit($clone->getCurrent());
					$finished = true;
					$subject->complete();
				})(),
				(function() use (&$subject, $emitter, $signal, &$finished) {
					while(yield $signal->advance()) {
						if($finished)
							return;
						
						$subject->complete();
						$subject = new Emitter();
						$emitter($subject->iterate());
					}
				})()
			];
		});
	}
}