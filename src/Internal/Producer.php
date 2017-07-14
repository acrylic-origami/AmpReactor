<?php

namespace AmpReactor\Internal;

use Amp\Deferred;
use Amp\Coroutine;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use React\Promise\PromiseInterface as ReactPromise;

/**
 * Trait used by Iterator implementations. Do not use this trait in your code, instead compose your class from one of
 * the available classes implementing \Amp\Iterator.
 * Note that it is the responsibility of the user of this trait to ensure that listeners have a chance to listen first
 * before emitting values.
 *
 * @internal
 */
abstract class Producer {
	/** @var Pointer<\Amp\Promise | null> */
	protected $complete;

	/**
	* Queue of values and backpressure promises
	* @var Queue
	*/
	protected $buffer;

	/** @var Pointer<\Amp\Deferred | null> */
	protected $waiting;

	/** @var null|array */
	protected $resolutionTrace;
	
	/** @var Pointer<bool> */
	protected $running_count;
	
	/** @var bool */
	protected $this_running = false;

	
	protected function __construct() {
		$this->buffer = new Queue();
		$this->complete = new Pointer(null);
		$this->waiting = new Pointer(null);
		$this->running_count = new Pointer(0);
	}
	
	public function __clone() {
		$this->buffer = clone $this->buffer;
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function advance(): Promise {
		if($this->this_running && !$this->buffer->is_empty()) {
			$buffer_item = $this->buffer->shift();
			if(!is_null($buffer_item) && !$buffer_item->resolved) {
				$buffer_item->resolved = true;
				$buffer_item->backpressure->resolve();
			}
		}
		
		if(!$this->this_running)
			$this->running_count++;
		
		$this->this_running = true;
		
		if(!$this->buffer->is_empty()) {
			return new Success(true);
		}
		
		if ($this->complete->value) {
			return $this->complete->value;
		}
		
		$this->waiting->value = $this->waiting->value ?? new Deferred;
		$buffer = $this->buffer;
		$waiting = $this->waiting;
		$complete = $this->complete; // eradicate all references to `$this` from the generator
		return new Coroutine((function() use ($buffer, $waiting, $complete) {
			while(!$complete->value && $buffer->is_empty())
				$result = yield $waiting->value->promise();
			// return \AmpReactor\Util\defer(function() use ($complete, $result) {
			return $complete->value ?? $result;
			// });
		})());
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCurrent() {
		if ($this->buffer->is_empty()) {
			if($this->complete->value)
				throw new \Error("The iterator has completed");
			else
				throw new \Error("Promise returned from advance() must resolve before calling this method");
		}

		return $this->buffer->peek()->value;
	}

	/**
	 * Emits a value from the iterator. The returned promise is resolved with the emitted value once all listeners
	 * have been invoked.
	 *
	 * @param mixed $value
	 *
	 * @return \Amp\Promise
	 *
	 * @throws \Error If the iterator has completed.
	 */
	protected function emit($value): Promise {
		if ($this->complete->value) {
			throw new \Error("Iterators cannot emit values after calling complete");
		}

		if ($value instanceof ReactPromise) {
			$value = Promise\adapt($value);
		}

		if ($value instanceof Promise) {
			$deferred = new Deferred;
			$value->onResolve(function ($e, $v) use ($deferred) {
				if ($this->complete->value) {
					$deferred->fail(
						new \Error("The iterator was completed before the promise result could be emitted")
					);
					return;
				}

				if ($e) {
					$this->fail($e);
					$deferred->fail($e);
					return;
				}

				$deferred->resolve($this->emit($v));
			});

			return $deferred->promise();
		}

		$pressure = new Deferred;
		$this->buffer->add(new class($value, $pressure) {
			use \Amp\Struct;
			public $value;
			public $backpressure;
			public $resolved = false;
			
			public function __construct($value, $backpressure) {
				$this->value = $value; $this->backpressure = $backpressure;
			}
		});

		if ($this->waiting->value !== null) {
			$waiting = $this->waiting->value;
			$this->waiting->value = null;
			$waiting->resolve(true);
		}

		return $pressure->promise();
	}

	/**
	 * Completes the iterator.
	 *
	 * @throws \Error If the iterator has already been completed.
	 */
	protected function complete() {
		if ($this->complete->value) {
			$message = "Iterator has already been completed";

			if (isset($this->resolutionTrace)) {
				// @codeCoverageIgnoreStart
				$trace = formatStacktrace($this->resolutionTrace);
				$message .= ". Previous completion trace:\n\n{$trace}\n\n";
				// @codeCoverageIgnoreEnd
			} else {
				$message .= ", define const AMP_DEBUG = true and enable assertions for a stacktrace of the previous completion.";
			}

			throw new \Error($message);
		}

		\assert((function () {
			if (\defined("AMP_DEBUG") && \AMP_DEBUG) {
				// @codeCoverageIgnoreStart
				$trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				\array_shift($trace); // remove current closure
				$this->resolutionTrace = $trace;
				// @codeCoverageIgnoreEnd
			}

			return true;
		})());

		$this->complete->value = new Success(false);

		if ($this->waiting->value !== null) {
			$waiting = $this->waiting->value;
			$this->waiting->value = null;
			$waiting->resolve($this->complete->value);
		}
	}

	protected function fail(\Throwable $exception) {
		$this->complete->value = new Failure($exception);

		if ($this->waiting->value !== null) {
			$waiting = $this->waiting->value;
			$this->waiting->value = null;
			$waiting->resolve($this->complete->value);
		}
	}
}
