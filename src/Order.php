<?php

namespace Waxis\Order;

class Order {

	private $_id			= null;
	
	// feature is basically the identifier
	private $_feature		= false;
	
	private $_totalCount	= 0;
	
	private $_searchParams	= array();
	
	protected $_descriptor	= array();
	
	public function __construct ($feature = false) {
		$this->_feature = $feature;
	}
	
	public function getInstance ($feature = false) {
		return new Order($feature);
	}

	public function getDescriptor () {
		$descriptor = $this->_descriptor;

		if (isset($descriptor[$this->_feature]))
			return $descriptor[$this->_feature];
			
		return false;
	}

	public function setDescriptor ($descriptor) {
		$this->_descriptor = $descriptor;
	}
	
	public function getVar ($var) {
		$descriptor = $this->getDescriptor();
		
		if (isset($descriptor[$var]))
			return $descriptor[$var];
	
		return false;
	}
	
	public function getFeature () {
		return $this->_feature;
	}
	
	public function featureExists () {
		return (bool) $this->getDescriptor();
	}
	
	public function setSearchParams ($params) {
		$this->_searchParams 	= $params;
		
		// have to turn down total count, as it may has changed do to extra params
		$this->_totalCount		= 0;
		
		return $this;
	}

	public function getSearchParams() {
		return $this->_searchParams;
	}
	
	private function _getSearchParams() {
		// if has been set
		if (!empty($this->_searchParams))
			return $this->_searchParams;
			
		// if not required
		if (!$this->getVar('searchParams'))
			return $this->_searchParams;
			
		// if not possible
		if (is_null($this->_id))
			return $this->_searchParams;

		$result = \DB::table($this->getVar('table'))->where('id', $this->_id)->first();
		
		$gatheredSearchParams = array();
		foreach ($this->getVar('searchParams') as $one) {
			$gatheredSearchParams[$one] = $result->{$one};
		}
		$this->setSearchParams($gatheredSearchParams);
		
		return $this->_searchParams;
	}
	
	public function createSelect ($id, $order, $direction = 'ASC') {
		$this->_id = $id;
		
		$total = $this->_getTotalCount();
		
		$select = '<select class="wax-change-order" data-id="'.$id.'" data-feature="'.$this->getFeature().'">';
		
		if ($direction == 'ASC') {
			for ($i = 1; $i <= $total; $i++) {
				$select .= "<option value='$i'".($i == $order ? ' selected="selected"' : '').">$i</option>";
			}
		} else {
			for ($i = $total; $i >= 1; $i--) {
				$select .= "<option value='$i'".($i == $order ? ' selected="selected"' : '').">$i</option>";
			}
		}
		
		
		$select .= '</select>';
		
		return $select;
	}
	
	public function createSelect2 ($id, $order, $direction = 'ASC') {
		$this->_id = $id;
		
		$total = $this->_getTotalCount();
		
		$select = '<select data-id="'.$id.'">';
		
		if ($direction == 'ASC') {
			for ($i = 1; $i <= $total; $i++) {
				$select .= "<option value='$i'".($i == $order ? ' selected="selected"' : '').">$i</option>";
			}
		} else {
			for ($i = $total; $i >= 1; $i--) {
				$select .= "<option value='$i'".($i == $order ? ' selected="selected"' : '').">$i</option>";
			}
		}
		
		
		$select .= '</select>';
		
		return $select;
	}
	
	public function changeOrder ($id, $newOrder) {
		$this->_id = $id;
		
		$oldOrder	= $this->_getElementOrder($id);

		$connector = $this->getConnector($id);
		
		# order decreased		
		if ($oldOrder > $newOrder) {
			$idsToIncrease = $this->_getIdsBetweenOrder($newOrder, $oldOrder - 1);

			$where = "id = $id";

			if ($connector !== false) {
				$where = "connector = $connector";
			}

			\DB::table($this->getVar('table'))->whereRaw($where)->update([$this->getVar('orderColumn') => $newOrder]);
			
			foreach ($idsToIncrease as $one) {
				\DB::table($this->getVar('table'))->where('id', $one)->increment($this->getVar('orderColumn'), 1);
			}

		# order increased
		} else {
			$idsToDecrease = $this->_getIdsBetweenOrder($oldOrder + 1, $newOrder);

			$where = "id = $id";

			if ($connector !== false) {
				$where = "connector = $connector";
			}

			\DB::table($this->getVar('table'))->whereRaw($where)->update([$this->getVar('orderColumn') => $newOrder]);
			
			foreach ($idsToDecrease as $one) {
				\DB::table($this->getVar('table'))->where('id', $one)->increment($this->getVar('orderColumn'), -1);
			}
		}
	}
	
	public function removeOrder ($id) {
		$this->_id = $id;

		$connector = $this->getConnector($id);
		
		$order 			= $this->_getElementOrder($id);
		$idsToChange	= $this->_getIdsAfterOrder($order);
		
		foreach ($idsToChange as $one) {
			\DB::table($this->getVar('table'))->where('id', $one)->increment($this->getVar('orderColumn'), -1);
		}

		$where = "id = $id";

		if ($connector !== false) {
			$where = "connector = $connector";
		}
		
		\DB::table($this->getVar('table'))->whereRaw($where)->update([$this->getVar('orderColumn') => 0]);
	}
	
	private function _getTotalCount () {
		if (!$this->_totalCount)
			$this->_setTotalCount();
		
		return $this->_totalCount;
	}
	
	private function _setTotalCount () {
		$this->_totalCount = $this->_getElementCount();
	}
	
	public function getNextOrder () {

		$sql = \DB::table($this->getVar('table'))
				 ->orderBy($this->getVar('orderColumn'), 'DESC');
				  
		$sql = $this->_addSearchParams($sql);
				  
		$result = $sql->first();

		$order = 0;

		if (isset($result->order)) {
			$order = $result->order;
		}
		
		return $order + 1;
	}
	
	private function _getIdsAfterOrder ($order) {

		$sql = \DB::table($this->getVar('table'))
				 ->orderBy($this->getVar('orderColumn'), 'ASC')
				 ->where($this->getVar('orderColumn'), '>', $order);
				  
		$sql = $this->_addSearchParams($sql);
		
		$return = array();
		
		foreach ($sql->get() as $one) {
			$return[] = $one->id;
		}
		
		return $return;
	}
	
	private function _getIdsBetweenOrder ($from, $to) {

		$sql = \DB::table($this->getVar('table'))
				 ->orderBy($this->getVar('orderColumn'), 'ASC')
				 ->whereBetween($this->getVar('orderColumn'), [$from, $to]);
				  
		$sql = $this->_addSearchParams($sql);
		
		$return = array();
		
		foreach ($sql->get() as $one) {
			$return[] = $one->id;
		}
		
		return $return;
	}
	
	private function _getElementOrder ($id) {

		$sql = \DB::table($this->getVar('table'))
				 ->where('id', $id);

		$result = $sql->first();

		$orderColumnName = $this->getVar('orderColumn');
		
		return $result->$orderColumnName;
	}
	
	private function _getElementCount () {

        $sql = \DB::table($this->getVar('table'));
				  
		$sql = $this->_addSearchParams($sql);

		if ($this->hasConnector()) {
			$sql->groupBy('connector');
		}
		
		return count($sql->get());
	}
	
	private function _addSearchParams ($sql) {
		foreach ($this->_getSearchParams() as $key => $value) {
			$sql->whereRaw("$key like '$value'");
		}
		return $sql;
	}

	# function required for translation

	public function hasConnector () {
		if ($this->getConnector() !== false) {
			return true;
		}

		return false;
	}

	public function getConnector ($id = null) {
		$query = \DB::table($this->getVar('table'));

		if ($id !== null) {
			$query->where('id', $id);
		}

		$first = $query->first();

		if (!empty($first)) {
			if (isset($first->connector)) {
				return $first->connector;
			}
		}

		return false;
	}
}