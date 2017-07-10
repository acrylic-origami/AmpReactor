<?php
namespace AmpReactor;
class Queue {
	protected $head;
	protected $tail;
	protected $_empty = false;
	protected $_started = false;
	public function __construct($list = null) {
		$list = $list ?? [];
		$head = null;
		$prev = null;
		foreach($list as $item) {
			$next = self::make_node(null, $item);
			if(is_null($head)) {
				$head = $next;
				$prev = $head;
			}
			else {
				$prev->next = $next;
				$prev = $next;
			}
		}
		$this->tail = new Pointer($prev);
		$this->head = new Pointer($head);
	}
	private static function make_node($next, $payload) {
		return new class($next, $payload) {
			use \Amp\Struct;
			public $next;
			public $payload;
			
			public function __construct($next, $payload) {
				$this->next = $next;
				$this->payload = $payload;
			}
		};
	}
	public function __clone() {
		// Separate pointers to the head of the queue, but keep sharing the tail pointer
		if(!is_null($this->head->value))
			$this->head = clone $this->head;
	}
	public function is_empty() {
		$head = $this->head->value;
		return is_null($head) || ($this->_empty && is_null($head->next)); // crucial to look at `head` for emptiness: tail is shared and is never unset.
	}
	public function is_singular() {
		return !$this->is_empty() && $this->head === $this->tail;
	}
	public function add($incoming) {
		$tail = $this->tail->value;
		$head = $this->head->value;
		
		$next = self::make_node(null, $incoming);
		
		if(!is_null($tail))
			$tail->next = $next;
		
		if(is_null($head))
			$this->head->value = $next;
		
		// advance tail pointer
		$this->tail->value = $next;
	}
	public function peek() {
		$head = $this->head->value;
		if($this->_empty)
			$head = $head->next;
		
		if(is_null($head))
			throw new \RuntimeException('Tried to `peek` an empty `LinkedList`.');
		
		// echo '***' . $head->payload . "\n";
		// var_dump($this);
		// echo "\n\n";
		return $head->payload;
	}
	public function shift() {
		$head = $this->head->value;
		if(is_null($head))
			throw new \RuntimeException('Tried to `shift` an empty `LinkedList`.');
		$next = $head->next;
		if($this->_empty && is_null($next))
			throw new \RuntimeException('Tried to `shift` an empty `LinkedList`.');
		
		if(!$this->_started) {
			// we could clone the head every time, but this saves some overhead
			$this->_started = true;
			$this->head = clone $this->head;
		}
		
		if($this->_empty) {
			$head = $next;
			$next = $next->next;
			$this->head->value = $head;
		}
		
		if(!is_null($next))
			$this->head->value = $next;
		
		$this->_empty = is_null($next);
		
		return $head->payload;
	}
}