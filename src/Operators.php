<?php

namespace AmpReactor;

use Amp\Success;
use Amp\Emitter;
use Amp\Coroutine;
use Amp\Iterator;
use Amp\Promise;

trait Operators {
	/**
	 * Promise of _at least_ the lifetime of the producer containing the values emitted since call.
	 * 
	 * **Spec**: 
	 * - The return value must not resolve sooner than the Producer throws an Exception, or runs out of values. The return value may resolve at _any point_ afterwards, but must resolve eventually.
	 * - Any values produced after even the _beginning_ of the call must be included in the return value.
	 */
	public function collapse(): Promise {
		$clone = clone $this;
		return new Coroutine((function() use ($clone) {
			$accumulator = [];
			while($clone->advance())
				$accumulator[] = $clone->getCurrent();
			
			return $accumulator;
		})());
	}
	
	/**
	 * [Combine multiple Producers into one by merging their emissions](http://reactivex.io/documentation/operators/merge.html)
	 * @param array<Iterator> $iterators
	 * @return Producer
	 */
	public static function merge(array $iterators): InteractiveProducer {
		return new self(function($_) use ($iterators) {
			return array_map(function(Iterator $iterator) {
				if($iterator instanceof InteractiveProducer)
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
	 * @param ?(function(T): (function(Emitter): Generator<Tv>) | \Amp\Iterator) $coercer - Transform `T`-valued items to Generators. $coercer defaults to the identity function, under the assumption  `T` is already a `(function(Emitter): Generator<Tv>) | \Amp\Iterator`.
	 */
	public function flat_map(callable $coercer = null): InteractiveProducer {
		$clone = clone $this;
		return new self(function(callable $emitter) use ($clone, $coercer) {
			return [(function() use ($emitter, $clone, $coercer) {
				$coroutines = [];
				while(yield $clone->advance()) {
					$item = $clone->getCurrent();
					if(!is_null($coercer))
						$item = $coercer($item);
					
					if(is_callable($item))
						// for Producerish (i.e. (function(Emitter): Generator<T>))
						$item = $item($emitter);
					else
						// for \Amp\Iterator
						$item = $this->iterator_to_emitting_generator($item, $emitter);
						
					// at this point, $item is definitely `\Generator`
					$coroutines[] = $coroutine = new Coroutine($item);
				}
				yield \Amp\Promise\all($coroutines);
			})()];
		});
	}
	
	/**
	 * Borrowed from Akka: [Pass incoming elements to a function that return a Promise result. When the promise resolves the result is passed downstream. Order is not preserved.](http://doc.akka.io/docs/akka/2.4.2/scala/stream/stages-overview.html#mapAsync)
	 * @param (function(T): Promise<Tv>) $coercer Transform `T`-valued items to Tv-valued promises. If null, defaults to identity, assuming `T` is already a `Promise<Tv>`.
	 */
	public function map_async_unordered(callable $coercer = null): InteractiveProducer {
		$clone = clone $this;
		return new self(function(callable $emitter) use ($clone, $coercer) {
			return [(function() use ($emitter, $clone, $coercer) {
				$futures = [];
				while(yield $clone->advance()) {
					$item = $clone->getCurrent();
					if(!is_null($coercer))
						$item = $coercer($item);
					
					// iffy about this coercion
					if(!$item instanceof Promise)
						$item = new Success($item);
					
					$futures[] = $item;
					$emitter($item);
				}
				yield \Amp\Promise\all($futures);
			})()];
		});
	}
	
	/**
	 * [Divide a Producer into a set of Producers that each emit a different subset of items from the original Producer.](http://reactivex.io/documentation/operators/groupby.html)
	 * 
	 * **Spec**:
	 * - Any items produced after the beginning call in the original Producer must be produced by exactly one of the `this`-typed Producers in the return value.
	 * @param <Tk as arraykey>(function(T): Tk) $keysmith - Assign `Tk`-valued keys to `T`-valued items
	 */
	public function group_by(callable $keysmith): InteractiveProducer {
		$clone = clone $this;
		return self::from_producerish(function($emitter) use ($clone, $keysmith) {
			$subjects = [];
			while(yield $clone->advance()) {
				$value = $clone->getCurrent();
				$key = $keysmith($value);
				if(!array_key_exists($key, $subjects)) {
					$subject = new Emitter();
					$subjects[$key] = $subject;
					$emitter(/* TEMP */self::create($subject->iterate()));
				}
				$subjects[$key]->emit($value);
			}
			foreach($subjects as $subject)
				$subject->complete();
		});
	}
	
	/**
	 * [Only emit an item from a Producer if a particular timespan has passed without it emitting another item](http://reactivex.io/documentation/operators/debounce.html)
	 * 
	 * **Spec**
	 * - The last value of the original Producer, if there is one, must be produced in the return value.
	 * @param int $timespan - The "timespan" as described above, in milliseconds.
	 */
	public function debounce(int $timespan): InteractiveProducer {
		$counter = 0;
		return (clone $this)->flat_map(function($v) use (&$counter, $timespan) {
			$counter++;
			return new \Amp\Producer(function($emitter) use ($v, &$counter, $timespan) {
				$stashed_counter = $counter;
				yield new \Amp\Delayed($timespan);
				if($stashed_counter === $counter)
					$emitter($v);
			});
		});
	}
	
	/**
	 * [periodically subdivide items from a Producer into Producer windows and emit these windows rather than emitting the items one at a time](http://reactivex.io/documentation/operators/window.html)
	 * 
	 * Note: if the `$signal` ends prematurely (before the end of the source `Producer`), the items continue to be produced on the last window.
	 * @param \Amp\Iterator $signal - Produce a value whenever a new window opens.
	 * @return Producer Produce Producers that group values from the original into windows dictated by `$signal`.
	 */
	public function window(Iterator $signal): InteractiveProducer {
		// should I accept producerish too? I end up using an Iterator internally anyways, so I'm leaning to no.
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
					$emitter($subject->iterate());
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
	
	/**
	 * [Periodically gather items emitted by a Producer into bundles and emit these bundles rather than emitting the items one at a time.](http://reactivex.io/documentation/operators/buffer.html)
	 * 
	 * **Spec**:
	 * - Any values produced by the original Producer after a call to `buffer` must be included in the return value.
	 * @param $signal - Produce a value whenever a new buffer is to replace the current one.
	 * @return - Produce Collections (which may be empty) bundling values emitted during each buffering period, as dictated by `$signal`.
	 */
	public function buffer(Iterator $signal): InteractiveProducer {
		$clone = clone($this);
		return static::from_producerish(function($emitter) use ($clone, $signal) {
			$clone->window($signal)
			      ->map(function($window) { return $window->collapse(); });
		});
	}
	
	/**
	 * [Transform the items [produced] by [a Producer] by applying a function to each item](http://reactivex.io/documentation/operators/map.html)
	 * 
	 * **Spec**:
	 * - The transformed values of all items produced after even the _beginning_ of the call must be included in the return value.
	 * - Note: order is not guaranteed to be preserved (although it is highly likely)
	 * @param $f - Transform items to type of choice `Tv`.
	 * @return - Emit transformed items from this Producer.
	 */
	public function map(callable $f): InteractiveProducer {
		$clone = clone $this;
		return static::from_producerish(function($emitter) use ($clone, $f) {
			while(yield $clone->advance())
				$emitter($f($clone->getCurrent()));
		});
	}
	
	/**
	 * [Emit only those items from a Producer that pass a predicate test](http://reactivex.io/documentation/operators/filter.html)
	 * 
	 * **Spec**:
	 * - Order from the initial `Producer` is preserved in the return value.
	 */
	public function filter(callable $f): InteractiveProducer {
		$clone = clone $this;
		return static::from_producerish(function($emitter) use ($clone) {
			while(yield $clone->advance()) {
				$item = $clone->getCurrent();
				if($f($item))
					$emitter($item);
			}
		});
	}
	
	
	/**
	 * [Apply a function to each item emitted by [a Producer], sequentially, and emit each successive value](http://reactivex.io/documentation/operators/scan.html)
	 * 
	 * **Spec**:
	 * - If there is exactly one value, then no values will be produced by the returned Producer. Otherwise, all values produced after even the _beginning_ of the call must be combined and included in the return value in order of production in the source Producer.
	 * @param $f - Transform two consecutive items to type of choice `Tv`.
	 * @return - Emit scanned items from this producer.
	 */
	public function scan(callable $f): InteractiveProducer {
		$clone = clone $this;
		return static::from_producerish(function($emitter) use ($clone) {
			$last = null;
			while(yield $clone->advance()) {
				if(!is_null($last))
					$emitter($f($last, $v));
				else
					$emitter($v);
				
				$last = $v;
			}
		});
	}
	
	/**
	 * [emit only the first _n_ items emitted by a Producer](http://reactivex.io/documentation/operators/take.html)
	 * 
	 * **Specs**
	 * - The return value may produce at most `$n` values, but must include all items produced since the beginning of the call or `$n` values, whichever is smaller.
	 */
	public function take($n): InteractiveProducer {
		$clone = clone $this;
		return static::from_producerish(function($emitter) use ($clone, $n) {
			for(; $n > 0 && (yield $clone->advance()); $n--)
				$emitter($clone->getCurrent());
		});
	}
	
	/**
	 * [Emit only the last item emitted by a Producer](http://reactivex.io/documentation/operators/last.html)
	 * 
	 * **Spec**: 
	 * - The return value must not resolve sooner than the Producer throws an Exception or runs out of values. The return value may resolve at _any point_ afterwards, but must resolve eventually.
	 * - If and only if no values are produced after the beginning of the call, the return value will resolve to null.
	 * @return - Contain the last value
	 */
	public function last(): Promise {
		$clone = clone $this;
		return new Coroutine((function() use ($clone) {
			while(yield $clone->advance()) $v = $clone->getCurrent();
			
			return $v;
		})());
	}
	
	/**
	 * [Emit only the first item emitted by a Producer](http://reactivex.io/documentation/operators/first.html)
	 * 
	 * **Spec**: 
	 * - If and only if no values are produced after the beginning of the call, the return value will resolve to null.
	 * @return - Contain the first value
	 */
	public function first(): Promise {
		$clone = clone $this;
		return new Coroutine((function() use ($clone) {
			if(yield $clone->advance())
				return $clone->getCurrent();
			else
				return null;
		})());
	}
	
	/**
	 * [Apply a function to each item emitted by a Producer, sequentially, and emit the final value](http://reactivex.io/documentation/operators/reduce.html)
	 * 
	 * **Spec**:
	 *   - If there is exactly one value, then the returned `Awaitable` will resolve to `null`. Otherwise, all values produced after even the _beginning_ of the call must be combined and included in the return value in order of production in the source Producer.
	 * @param $f - Transform two consecutive items to type of choice `Tv`.
	 * @return - Emit the final result of sequential reductions.
	 */
	public function reduce(callable $f): Promise {
		return (clone $this)->scan($f)->last();
	}
	
	/**
	 * [Emit the most recent items emitted by a Producer within periodic time intervals](http://reactivex.io/documentation/operators/sample.html)
	 * 
	 * @param $signal - Produce a value whenever a new window opens.
	 * @return - Produce the last value emitted during a window dictated by `$signal`.
	 */
	public function sample(Iterator $signal) {
		return (clone $this)->window($signal)
		                    ->map_async_unordered(function($window) {
		                    	return $window->last();
		                    });
	}
	
	/**
	 * [Create a Producer that emits no items but terminates normally](http://reactivex.io/documentation/operators/empty-never-throw.html)
	 */
	public static function empty(): InteractiveProducer {
		return static::from_producerish(function($_) {
			if(false) yield null;
		});
	}
	
	/**
	 * [Create a Producer that emits no items and terminates with an error](http://reactivex.io/documentation/operators/empty-never-throw.html)
	 */
	public static function throw(\Exception $e): InteractiveProducer {
		return static::from_producerish(function($_) {
			throw $e;
			if(false) yield null;
		});
	}
	
	public static function never(): InteractiveProducer {
		return self::from_producerish(function($_) {
			yield (new Deferred)->promise();
		});
	}
	
	/**
	 * [Create a Producer that emits a sequence of integers spaced by a given time interval](http://reactivex.io/documentation/operators/interval.html)
	 * 
	 * Note: in extremely high-concurrency situations, this might get very inaccurate _and_ skewed.
	 */
	public static function interval($usecs): InteractiveProducer {
		return static::from_producerish(function($emitter) use ($usecs) {
			for($i = 0; ; $i++)
				$emitter(yield new Delayed($usecs, $i));
		});
	}
	
	
	/**
	 * [create a Producer that emits a particular item](http://reactivex.io/documentation/operators/just.html)
	 * 
	 * Note: very likely to, but _might_ not terminate immediately.
	 */
	public static function just($item): InteractiveProducer {
		return static::from_producerish(function($emitter) use ($item) {
			$emitter($item);
			if(false) yield null;
		});
	}
	
	/**
	 * [Create a Producer that emits a particular range of sequential integers](http://reactivex.io/documentation/operators/range.html)
	 * 
	 * Note: in a free `foreach-await` loop, this will _busy-wait_ until it ends or a parallel coroutine wakes up if ever
	 */
	public static function range($n, $max): InteractiveProducer {
		return static::from_producerish(function($emitter) use ($n, $max) {
			for(; $n < $max; $n++) {
				$emitter($n);
				yield \AmpReactor\Util\defer();
			}
		});
	}
	
	/**
	 * [Create a Producer that emits a particular item multiple times](http://reactivex.io/documentation/operators/repeat.html)
	 * 
	 * Note: in a free `foreach-await` loop, this will _busy-wait_ until it ends or a parallel coroutine wakes up if ever
	 * @param $v The value to repeat
	 * @param $n Nullable number of repeats; null will continue forever
	 */
	public static function repeat($item, $times = null): InteractiveProducer {
		return static::from_producerish(function($emitter) use ($item, $times) {
			for(; is_null($times) || $times > 0; is_null($n) || $times--) {
				$emitter($item);
				yield \AmpReactor\Util\defer();
			}
		});
	}
	
	/**
	 * [Create a Producer that emits a particular sequence of items multiple times](http://reactivex.io/documentation/operators/repeat.html)
	 * 
	 * Note: in a free `foreach-await` loop, this will _busy-wait_ until it ends or a parallel coroutine wakes up if ever
	 * @param $item The value to repeat
	 * @param $n Nullable number of repeats; null will continue forever
	 */
	public static function repeat_sequence($items, $times = null): InteractiveProducer {
		return static::from_producerish(function($emitter) use ($items, $times) {
			for(; is_null($times) || $times > 0; is_null($n) || $times--) {
				foreach($items as $item) {
					$emitter($item);
					yield \AmpReactor\Util\defer();
				}
			}
		});
	}
	
	/**
	 * [Create a Producer that emits a particular sequence of items multiple times](http://reactivex.io/documentation/operators/repeat.html)
	 * 
	 * Note: in a free `foreach-await` loop, this will _busy-wait_ until it ends or a parallel coroutine wakes up if ever
	 * @param $item The value to repeat
	 * @param $n Nullable number of repeats; null will continue forever
	 */
	public static function timer($item, $delay): InteractiveProducer {
		return static::from_producerish(function($emitter) use ($item, $delay) {
			$emitter(yield new Delayed($delay, $item));
			if(false) yield null;
		});
	}
	
	/**
	 * [Combine the emissions of multiple Producers together via a specified function and emit single items for each combination based on the results of this function.](http://reactivex.io/documentation/operators/zip.html)
	 * @param $A - Producer of left-hand items
	 * @param $B - Producer of right-hand items
	 * @param $combiner - Zips left- and right-hand items to `Tx`-valued items.
	 */
	public static function zip(Iterator $left, Iterator $right, callable $combiner): InteractiveProducer {
		if($left instanceof InteractiveProducer) $left = clone $left;
		if($right instanceof InteractiveProducer) $right = clone $right;
		
		return static::from_producerish(function($emitter) use ($left, $right, $combiner) {
			while(!in_array(false, yield \Amp\Promise\all([$left->advance(), $right->advance()]), true)) {
				$emitter($combiner($left->getCurrent(), $right->getCurrent()));
			}
		});
	}
	
	/**
	 * [When an item is emitted by either of two Producers, combine the latest item emitted by each Producer via a specified function and emit items based on the results of this function](http://reactivex.io/documentation/operators/combinelatest.html)
	 * 
	 * Note: if one source `Producer` never produces values, no values are produced by the return value either.
	 */
	public static function combine_latest(Iterator $left, Iterator $right, callable $combiner) {
		if($left instanceof InteractiveProducer) $left = clone $left;
		else $left = self::create($left);
		
		if($right instanceof InteractiveProducer) $right = clone $right;
		else $right = self::create($right);
		
		return new self(function($emitter) use ($left, $right, $combiner) {
			$left_latest = null;
			$right_latest = null;
			return [
				$left->map(function($item) use ($emitter, $combiner, &$left_latest, &$right_latest) {
					$left_latest = $item;
					if($right_latest !== null)
						$emitter($combiner($left_latest, $right_latest));
				}),
				$right->map(function($item) use ($emitter, $combiner, &$left_latest, &$right_latest) {
					$right_latest = $item;
					if($left_latest !== null)
						$emitter($combiner($left_latest, $right_latest));
				})
			];
		});
	}
	
	public static function join(Iterator $left, Iterator $right, callable $left_timer, callable $right_timer, callable $combiner) {
		if(!$left instanceof InteractiveProducer) $left = self::create($left);
		if(!$right instanceof InteractiveProducer) $right = self::create($right);
		
		return new self(function($emitter) use ($left, $right, $left_timer, $right_timer, $combiner) {
			$left_delayed_flag = null;
			$right_delayed_flag = null;
			return [
				$left->map_async_unordered(function($item) use ($emitter, $left_timer, $right, $combiner, &$left_delayed_flag) {
					$left_delayed_flag = new Pointer(true);
					$right = clone $right;
					return \Amp\Promise\all([
						new Coroutine((function() use ($item, $left_timer, $left_delayed_flag) {
							yield $left_timer($item);
							$left_delayed_flag->v = false;
						})()),
						new Coroutine((function() use ($emitter, $left_delayed_flag, $right) {
							while(yield $right->advance()) {
								if(!$left_delayed_flag->v)
									return;
								$emitter($right->getCurrent());
							}
						}))
					]);
				}),
				$right->map_async_unordered(function($item) use ($emitter, $right_timer, $left, $combiner, &$right_delayed_flag) {
					$right_delayed_flag = new Pointer(true);
					$left = clone $left;
					return \Amp\Promise\all([
						new Coroutine((function() use ($item, $right_timer, $right_delayed_flag) {
							yield $right_timer($item);
							$right_delayed_flag->v = false;
						})()),
						new Coroutine((function() use ($emitter, $right_delayed_flag, $left) {
							while(yield $left->advance()) {
								if(!$right_delayed_flag->v)
									return;
								$emitter($left->getCurrent());
							}
						}))
					]);
				})
			];
		});
	}
}
