<?php
namespace AmpReactor;
use Amp\Promise;
abstract class BaseProducer implements \Amp\Iterator {
	/**
	 * @var Pointer<bool>
	 */
	protected $running_count;
	
	/**
	 * @var bool
	 */
	protected $this_running = false;
	
	/**
	 * @var Pointer<Pointer<bool>>
	 */
	protected $some_running;
	
	/**
	 * @var Queue<T>
	 */
	protected $buffer;
	
	public function __clone() {
		$this->buffer = clone $this->buffer;
	}
	abstract protected function _attach();
	protected function _detach() {
		if($this->this_running) {
			$this->running_count->value--;
			if($this->running_count->value === 0) {
				$this->some_running->value->value = false;
			}
		}
	}
	public function __destruct() {
		$this->_detach();
	}
	
	public function advance(): Promise {
		$this->some_running->value->value = true;
		$this->this_running = true;
		
		if(!$this->buffer->is_empty()) {
			$this->buffer->shift();
		}
		
		return $this->_produce();
	}
	// abstract public function getCurrent();
	
	
}