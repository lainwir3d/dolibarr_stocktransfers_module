<?php
/* Copyright (C) 2026 Spirits Run
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    stocktransfers/class/api_stocktransfers.class.php
 * \ingroup stocktransfers
 * \brief   REST API for warehouse-to-warehouse stock transfers.
 *
 * Follows the bundled-module API style (see api_shipments). The status
 * transitions go through StockTransfer::applyStatusTransition(), the same
 * method the web page uses: movements are created before the new status is
 * persisted and illegal jumps are refused, so the API can never produce a
 * SENT/RECEIVED transfer whose stock movements are missing or partial.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/stocktransfers/lib/stocktransfers_transfer.class.php');

/**
 * API class for stock transfers
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Stocktransfers extends DolibarrApi
{
	/**
	 * @var StockTransfer $transfer {@type StockTransfer}
	 */
	public $transfer;

	/**
	 * @var array   $FIELDS     Mandatory fields, checked when create and update object
	 */
	public static $FIELDS = array(
		'fk_depot1',
		'fk_depot2',
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
		$this->transfer = new StockTransfer($this->db);
	}

	/**
	 * Get properties of a transfer object by id.
	 *
	 * @param   int         $id        ID of the transfer
	 * @return  Object                  The transfer with its product lines
	 */
	public function get($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('stock', 'mouvement', 'lire')) {
			throw new RestException(403);
		}

		$result = $this->transfer->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Transfer not found');
		}
		if (!DolibarrApi::_checkAccessToResource('transfer', $this->transfer->rowid)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		return $this->_cleanObjectDatas($this->transfer);
	}

	/**
	 * List transfers.
	 *
	 * @param   string      $sortfield  Sort field
	 * @param   string      $sortorder  Sort direction
	 * @param   int         $limit      Limit for list
	 * @param   int         $page       Page number
	 * @param   string      $sqlfilters Other criteria to filter answers. Example: "(t.status:=:'1')"
	 * @return  array                   Array of transfer objects
	 */
	public function index($sortfield = 't.rowid', $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('stock', 'mouvement', 'lire')) {
			throw new RestException(403);
		}

		// The sortable fields are whitelisted: the values come from the request.
		$sortablefields = array('t.rowid', 't.ts_create', 't.label', 't.inventorycode', 't.shipper', 't.status', 't.fk_depot1', 't.fk_depot2');
		if (!in_array($sortfield, $sortablefields, true)) $sortfield = 't.rowid';
		$sortorder = strtoupper($sortorder) == 'ASC' ? 'ASC' : 'DESC';

		$offset = 0;
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;
		}

		$sql = "SELECT t.rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX."stocktransfers_transfers AS t";
		$sql .= ' WHERE t.rowid >= 0'; // In case sqlfilters is not used
		if (DolibarrApi::_checkFilters($sqlfilters)) {
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters);
		} else {
			throw new RestException(400, 'Error when validating parameter sqlfilters '.$sqlfilters);
		}
		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(500, 'Error when retrieving the transfer list: '.$this->db->lasterror());
		}

		$i = 0;
		$num = $this->db->num_rows($result);
		$min = min($num, ($limit <= 0 ? $num : $limit));
		$data = array();
		while ($i < $min) {
			$obj = $this->db->fetch_object($result);
			$transfer_static = new StockTransfer($this->db);
			if ($transfer_static->fetch((int) $obj->rowid) > 0) {
				$data[] = $this->_cleanObjectDatas($transfer_static);
			}
			$i++;
		}
		$this->db->free($result);

		return $data;
	}

	/**
	 * Create a transfer. Lines can be passed directly as an array of
	 * {product_id, qty, batch, note} entries.
	 *
	 * @param   array       $request_data  Request data: fk_depot1, fk_depot2 and label are expected; optional lines, shipper, dates...
	 * @return  int                        ID of the created transfer (always as draft)
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('stock', 'mouvement', 'creer')) {
			throw new RestException(403);
		}

		$request_data = (array) $request_data;
		// Strip the keys the API does not own.
		unset($request_data['id'], $request_data['rowid'], $request_data['status'], $request_data['s_products']);
		// A transfer created through the API always starts as a draft.
		$request_data['status'] = '0';

		$lines = array();
		if (isset($request_data['lines'])) {
			$lines = (array) $request_data['lines'];
			unset($request_data['lines']);
		}

		foreach ($request_data as $field => $value) {
			if ($field == 'caller') continue;
			$this->transfer->{$field} = $value;
		}
		$this->transfer->fk_depot1 = (int) $this->transfer->fk_depot1;
		$this->transfer->fk_depot2 = (int) $this->transfer->fk_depot2;

		$result = $this->transfer->create();
		if ($result < 1) {
			throw new RestException(500, 'Error creating transfer', array_merge(array($this->transfer->error), $this->transfer->errors));
		}

		// Add the optional lines to the fresh draft.
		foreach ($lines as $line) {
			$line = (object) $line;
			$this->addLineToTransfer((int) $line->product_id, (float) $line->qty, isset($line->batch) ? (string) $line->batch : '', isset($line->note) ? (string) $line->note : '');
		}

		return $this->transfer->rowid;
	}

	/**
	 * Update a transfer: card fields and, when the posted status differs,
	 * the status transition (movements are created before the new status is
	 * persisted; illegal jumps are refused — same method as the web page).
	 *
	 * @param   int         $id            ID of the transfer
	 * @param   array       $request_data  Request data
	 * @return  Object                     The updated transfer
	 */
	public function put($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('stock', 'mouvement', 'creer')) {
			throw new RestException(403);
		}

		$result = $this->transfer->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Transfer not found');
		}
		if (!DolibarrApi::_checkAccessToResource('transfer', $this->transfer->rowid)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		$request_data = (array) $request_data;
		unset($request_data['id'], $request_data['rowid'], $request_data['s_products']);

		$newstatus = isset($request_data['status']) ? (string) $request_data['status'] : (string) $this->transfer->status;
		unset($request_data['status']);

		foreach ($request_data as $field => $value) {
			if ($field == 'caller') continue;
			$this->transfer->{$field} = $value;
		}
		$this->transfer->fk_depot1 = (int) $this->transfer->fk_depot1;
		$this->transfer->fk_depot2 = (int) $this->transfer->fk_depot2;

		$result = $this->transfer->applyStatusTransition(DolibarrApiAccess::$user, $newstatus);
		if ($result < 0) {
			throw new RestException(500, 'Error when updating transfer', array_merge(array($this->transfer->error), $this->transfer->errors));
		}

		$this->transfer->fetch((int) $id); // Reload with the persisted state.

		return $this->_cleanObjectDatas($this->transfer);
	}

	/**
	 * Add a product line to a draft transfer.
	 *
	 * @param   int         $id            ID of the transfer
	 * @param   array       $request_data  {product_id, qty, batch?, note?}
	 * @return  Object                     The updated transfer
	 *
	 * @url     POST {id}/lines
	 */
	public function postLine($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('stock', 'mouvement', 'creer')) {
			throw new RestException(403);
		}

		$result = $this->transfer->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Transfer not found');
		}

		$line = (object) $request_data;
		$pid = (int) $line->product_id;
		$qty = (float) $line->qty;
		if ($pid <= 0 || $qty == 0) {
			throw new RestException(400, 'product_id and qty are mandatory');
		}
		// Only drafts can gain lines: a sent/received transfer's movements
		// would no longer match the line list.
		if ($this->transfer->status != '0') {
			throw new RestException(400, 'Lines can only be added to a draft transfer');
		}

		$this->addLineToTransfer($pid, $qty, isset($line->batch) ? (string) $line->batch : '', isset($line->note) ? (string) $line->note : '');

		$result = $this->transfer->update();
		if ($result < 0) {
			throw new RestException(500, 'Error when adding the line', array_merge(array($this->transfer->error), $this->transfer->errors));
		}

		$this->transfer->fetch((int) $id);
		return $this->_cleanObjectDatas($this->transfer);
	}

	/**
	 * Delete a product line from a draft transfer.
	 *
	 * @param   int         $id            ID of the transfer
	 * @param   int         $product_id    ID of the product line to remove
	 * @return  Object                     The updated transfer
	 *
	 * @url     DELETE {id}/lines
	 */
	public function deleteLine($id, $product_id)
	{
		if (!DolibarrApiAccess::$user->hasRight('stock', 'mouvement', 'creer')) {
			throw new RestException(403);
		}

		$result = $this->transfer->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Transfer not found');
		}
		if ($this->transfer->status != '0') {
			throw new RestException(400, 'Lines can only be removed from a draft transfer');
		}

		$pid = (int) $product_id;
		if (!isset($this->transfer->products[$pid])) {
			throw new RestException(404, 'Line not found');
		}
		unset($this->transfer->products[$pid]);

		$result = $this->transfer->update();
		if ($result < 0) {
			throw new RestException(500, 'Error when removing the line', array_merge(array($this->transfer->error), $this->transfer->errors));
		}

		$this->transfer->fetch((int) $id);
		return $this->_cleanObjectDatas($this->transfer);
	}

	/**
	 * Delete a transfer (draft only — a sent or received transfer has real
	 * stock movements that only a status reversal can undo).
	 *
	 * @param   int         $id            ID of the transfer
	 * @return  array                       Without the deleted transfer
	 */
	public function delete($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('stock', 'mouvement', 'creer')) {
			throw new RestException(403);
		}

		$result = $this->transfer->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Transfer not found');
		}
		if ($this->transfer->status != '0') {
			throw new RestException(400, 'Only draft transfers can be deleted; revert the status first');
		}

		$result = $this->transfer->delete(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error when deleting the transfer', array_merge(array($this->transfer->error), $this->transfer->errors));
		}

		return array('success' => true);
	}

	/**
	 * Add (or replace) one product line on the in-memory transfer and
	 * persist the line list.
	 *
	 * @param   int     $product_id    Product id
	 * @param   float   $qty           Quantity to move
	 * @param   string  $batch         Batch/lot value ('' when unused)
	 * @param   string  $note          Line note
	 * @return  void
	 */
	private function addLineToTransfer($product_id, $qty, $batch = '', $note = '')
	{
		if (isset($this->transfer->products[$product_id])) {
			$this->transfer->products[$product_id]['n'] = (float) $this->transfer->products[$product_id]['n'] + $qty;
		} else {
			$this->transfer->products[$product_id] = array(
				'id' => $product_id,
				'n' => $qty,
				'b' => $batch,
				'm' => $note,
			);
		}
	}

	/**
	 * Clean sensible object datas
	 *
	 * @param   Object  $object     Object to clean
	 * @return  Object              Object with cleaned properties
	 */
	protected function _cleanObjectDatas($object)
	{
		// The serialized product blob is a storage detail: expose the
		// parsed lines instead.
		$object->products = is_array($object->products) ? $object->products : array();

		unset($object->db);
		unset($object->error);
		unset($object->errors);
		unset($object->s_products);

		return $object;
	}
}
