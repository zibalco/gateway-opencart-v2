<?php

class ModelPaymentZibal extends Model
{
	public function getMethod($address)
	{
		$this->load->language('payment/zibal');

		if ($this->config->get('zibal_status')) {

			$status = true;

		} else {

			$status = false;
		}

		$method_data = array ();

		if ($status) {

			$method_data = array (
        		'code'       => 'zibal',
        		'title'      => $this->language->get('text_title'),
				'terms'      => null,
				'sort_order' => $this->config->get('zibal_sort_order')
			);
		}

		return $method_data;
	}

	// Created lazily so stores that installed an older version of the plugin get the table too.
	public function createTable()
	{
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "zibal_transaction` (
			`zibal_transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`track_id` VARCHAR(32) NOT NULL,
			`amount` BIGINT(20) NOT NULL,
			`status` VARCHAR(16) NOT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`zibal_transaction_id`),
			UNIQUE KEY `track_id` (`track_id`),
			KEY `order_id` (`order_id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8");
	}

	public function addTransaction($order_id, $track_id, $amount)
	{
		$this->createTable();

		$this->db->query("INSERT INTO `" . DB_PREFIX . "zibal_transaction` SET `order_id` = '" . (int)$order_id . "', `track_id` = '" . $this->db->escape($track_id) . "', `amount` = '" . (int)$amount . "', `status` = 'pending', `date_added` = NOW(), `date_modified` = NOW()");
	}

	public function getTransaction($track_id)
	{
		$this->createTable();

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zibal_transaction` WHERE `track_id` = '" . $this->db->escape($track_id) . "'");

		return $query->num_rows ? $query->row : false;
	}

	public function getPaidTransaction($order_id)
	{
		$this->createTable();

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zibal_transaction` WHERE `order_id` = '" . (int)$order_id . "' AND `status` = 'paid' LIMIT 1");

		return $query->num_rows ? $query->row : false;
	}

	// Writes straight to order_history so the order status, stock and customer emails are left alone.
	public function addHistoryNote($order_id, $order_status_id, $comment)
	{
		$this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET `order_id` = '" . (int)$order_id . "', `order_status_id` = '" . (int)$order_status_id . "', `notify` = '0', `comment` = '" . $this->db->escape($comment) . "', `date_added` = NOW()");
	}

	// Moves a transaction from one status to another; returns false if another request already moved it.
	public function updateTransactionStatus($track_id, $from, $to)
	{
		$this->db->query("UPDATE `" . DB_PREFIX . "zibal_transaction` SET `status` = '" . $this->db->escape($to) . "', `date_modified` = NOW() WHERE `track_id` = '" . $this->db->escape($track_id) . "' AND `status` = '" . $this->db->escape($from) . "'");

		return $this->db->countAffected() == 1;
	}
}
