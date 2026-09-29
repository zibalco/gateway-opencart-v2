<?php

class ControllerPaymentZibal extends Controller
{
	// Raw result of the last call to Zibal, kept for the order history notes.
	protected $last_response = array ('http_code' => 0, 'error' => '', 'body' => '');

	public function index()
	{
		$this->load->language('payment/zibal');
		$this->load->model('checkout/order');
		$this->load->model('payment/zibal');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		$amount     = $this->getAmount($order_info);

		$data['button_confirm'] = $this->language->get('button_confirm');

		$data['error_warning'] = false;

		if ($amount === false) {

			$this->addNote($order_info, $this->language->get('text_note_currency') . ($order_info ? $order_info['currency_code'] : '-'));

			$data['error_warning'] = $this->language->get('error_currency');

		} elseif (extension_loaded('curl')) {

			$merchant    = $this->config->get('zibal_api');
			$callback    = str_replace('&amp;', '&', $this->url->link('payment/zibal/callback', 'order_id=' . $order_info['order_id'] . '&token=' . $this->getToken($order_info['order_id']), 'SSL'));
			$telephone   = $order_info['telephone'];
			$order_id    = $order_info['order_id'];
			$description = 'پرداخت سفارش شناسه ' . $order_info['order_id'];

			$params = array (

				'merchant'    => $merchant,
				'amount'      => $amount,
				'callbackUrl' => $callback,
				'mobile'      => $telephone,
				'orderId'     => $order_id,
				'description' => $description
			);

			$result = $this->common('https://gateway.zibal.ir/request', $params);

			if ($result && isset($result->result) && $result->result == 100 && isset($result->trackId) && ctype_digit((string)$result->trackId)) {

				// Remember which trackId belongs to this order and amount, so the callback can check it.
				$this->model_payment_zibal->addTransaction($order_id, (string)$result->trackId, $amount);

				$this->addNote($order_info, $this->language->get('text_note_request') . $result->trackId, false, $this->config->get('config_order_status_id'));

				$data['action'] = 'https://gateway.zibal.ir/start/' . $result->trackId;

			} else {

				$this->addNote($order_info, $this->language->get('text_note_request_failed'), true);

				$data['error_warning'] = $this->language->get('error_generic');
			}

		} else {

			$this->addNote($order_info, $this->language->get('text_note_curl'));

			$data['error_warning'] = $this->language->get('error_generic');
		}

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/zibal.tpl')) {

			return $this->load->view($this->config->get('config_template') . '/template/payment/zibal.tpl', $data);

		} else {

			return $this->load->view('default/template/payment/zibal.tpl', $data);
		}
	}

	public function callback()
	{
		$this->load->language('payment/zibal');
		$this->load->model('checkout/order');
		$this->load->model('payment/zibal');

		$this->document->setTitle($this->language->get('heading_title'));

		// Prefer the signed order id from the callback URL; fall back to the session.
		$order_id = false;

		if (isset($this->request->get['order_id'], $this->request->get['token']) && $this->checkToken($this->request->get['order_id'], $this->request->get['token'])) {

			$order_id = (int)$this->request->get['order_id'];

		} elseif (isset($this->session->data['order_id'])) {

			$order_id = $this->session->data['order_id'];
		}

		$order_info = $order_id ? $this->model_checkout_order->getOrder($order_id) : false;

		$data['heading_title'] = $this->language->get('heading_title');

		$data['button_continue'] = $this->language->get('button_continue');
		$data['continue']        = $this->url->link('common/home', '', 'SSL');

		$data['error_warning'] = false;

		// Zibal returns the customer to the callback URL with GET parameters.
		$success = isset($this->request->get['success']) ? $this->request->get['success'] : (isset($this->request->post['success']) ? $this->request->post['success'] : '');
		$trackId = isset($this->request->get['trackId']) ? $this->request->get['trackId'] : (isset($this->request->post['trackId']) ? $this->request->post['trackId'] : '');
		$orderId = isset($this->request->get['orderId']) ? $this->request->get['orderId'] : (isset($this->request->post['orderId']) ? $this->request->post['orderId'] : '');

		// A paid order stays paid: reopening the callback link, with any parameters, never calls verify
		// again, never writes to the order history and always shows the success page.
		$paid_transaction = $order_info ? $this->model_payment_zibal->getPaidTransaction($order_info['order_id']) : false;

		if ($paid_transaction) {

			if (is_string($trackId) && ctype_digit($trackId) && $trackId !== $paid_transaction['track_id']) {

				// A second payment attempt for an order that is already paid is not verified; leave it for the admin.
				$this->log->write('Zibal: order_id ' . $order_info['order_id'] . ' is already paid with trackId ' . $paid_transaction['track_id'] . '; callback for trackId ' . $trackId . ' was ignored and not verified.');
			}

			$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));

			return;
		}

		if ($success !== '' && $trackId !== '' && $orderId !== '') {

			if ($success == 1) {

				// The trackId must be one we stored for this exact order when the payment was requested.
				$transaction = false;

				if (is_string($trackId) && ctype_digit($trackId)) {

					$transaction = $this->model_payment_zibal->getTransaction($trackId);
				}

				if ($order_info && $transaction && $transaction['order_id'] == $order_info['order_id'] && $order_id == $orderId && $orderId == $order_info['order_id']) {

					$amount = $this->getAmount($order_info);

					if ($transaction['status'] == 'paid') {

						// Page reloaded after a successful payment: nothing more to do.

					} elseif ($transaction['status'] != 'pending') {

						$this->addNote($order_info, $this->language->get('text_note_status') . $transaction['status'] . "\n" . $this->formatParams());

						$data['error_warning'] = $this->language->get('error_generic');

					} elseif ($amount === false || $amount != $transaction['amount']) {

						// The order total changed after the payment request was made.
						$this->addNote($order_info, $this->language->get('text_note_amount_changed') . "\n" . $this->formatParams());

						$data['error_warning'] = $this->language->get('error_generic');

					} else {

						$params = array (

							'merchant' => $this->config->get('zibal_api'),
							'trackId'  => $trackId
						);

						$result = $this->common('https://gateway.zibal.ir/verify', $params);

						if (!$result || !isset($result->result)) {

							// Connection failure is not a failed payment; the customer can reload to retry verify.
							$this->addNote($order_info, $this->language->get('text_note_connection'), true);

							$data['error_warning'] = $this->language->get('error_generic');

						} elseif ($result->result == 100) {

							if (!isset($result->amount, $result->orderId) || (int)$result->amount != $transaction['amount'] || $result->orderId != $order_info['order_id']) {

								// Zibal has settled the money, so keep a record for a manual refund or review.
								$this->model_payment_zibal->updateTransactionStatus($trackId, 'pending', 'review');

								$this->addNote($order_info, $this->language->get('text_note_mismatch'), true);

								$data['error_warning'] = $this->language->get('error_generic');

							} elseif ($this->model_payment_zibal->updateTransactionStatus($trackId, 'pending', 'paid')) {

								// Only the request that flips pending -> paid records the payment.
								$comment  = $this->language->get('text_paid') . "\n";
								$comment .= $this->language->get('text_track_id') . $trackId . "\n";
								$comment .= $this->language->get('text_card_number') . (isset($result->cardNumber) ? $result->cardNumber : '-') . "\n";
								$comment .= $this->language->get('text_paid_at') . (isset($result->paidAt) ? $result->paidAt : '-');

								$this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->config->get('zibal_order_status_id'), htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'));
							}

						} elseif ($result->result == 201) {

							// Verified earlier but never recorded here (e.g. the first response was lost): needs manual review.
							$this->model_payment_zibal->updateTransactionStatus($trackId, 'pending', 'review');

							$this->addNote($order_info, $this->language->get('text_note_201'), true);

							$data['error_warning'] = $this->language->get('error_generic');

						} else {

							// 202 means Zibal says it was not paid; other codes (e.g. merchant errors) keep it retryable.
							if ($result->result == 202) {

								$this->model_payment_zibal->updateTransactionStatus($trackId, 'pending', 'failed');
							}

							$this->addNote($order_info, $this->language->get('text_note_verify_failed'), true);

							$data['error_warning'] = $this->language->get('error_generic');
						}
					}

				} else {

					$this->addNote($order_info, $this->language->get('text_note_not_found') . "\n" . $this->formatParams());

					$data['error_warning'] = $this->language->get('error_generic');
				}

			} else {

				$this->addNote($order_info, $this->language->get('text_note_cancel') . "\n" . $this->formatParams());

				$data['error_warning'] = $this->language->get('error_generic');
			}

		} else {

			$this->addNote($order_info, $this->language->get('text_note_data') . "\n" . $this->formatParams());

			$data['error_warning'] = $this->language->get('error_generic');
		}

		if ($data['error_warning']) {

			$data['breadcrumbs'] = array ();

			$data['breadcrumbs'][] = array (

				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/home', '', 'SSL')
			);

			$data['breadcrumbs'][] = array (

				'text' => $this->language->get('text_basket'),
				'href' => $this->url->link('checkout/cart', '', 'SSL')
			);

			$data['breadcrumbs'][] = array (

				'text' => $this->language->get('text_checkout'),
				'href' => $this->url->link('checkout/checkout', '', 'SSL')
			);

			$data['header'] = $this->load->controller('common/header');
			$data['footer'] = $this->load->controller('common/footer');

			if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/zibal_callback.tpl')) {

				$this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/payment/zibal_callback.tpl', $data));

			} else {

				$this->response->setOutput($this->load->view('default/template/payment/zibal_callback.tpl', $data));
			}

		} else {

			$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
		}
	}

	// Adds a note to the order history without changing the order status or emailing the customer.
	private function addNote($order_info, $note, $with_response = false, $order_status_id = false)
	{
		$comment = $note;

		if ($with_response) {

			$comment .= "\n" . $this->formatResponse();
		}

		if (!$order_info) {

			$this->log->write('Zibal: ' . str_replace(array ("\r", "\n"), ' | ', $comment));

			return;
		}

		// Confirmed orders are visible to the customer, so their raw Zibal response goes to the error log instead.
		if ($order_info['order_status_id'] && $with_response) {

			$this->log->write('Zibal order_id ' . $order_info['order_id'] . ': ' . str_replace(array ("\r", "\n"), ' | ', $comment));

			$comment = $note . "\n" . $this->language->get('text_note_log');
		}

		if (!$order_status_id) {

			$order_status_id = $this->config->get('zibal_failed_status_id') ? $this->config->get('zibal_failed_status_id') : $this->config->get('config_order_status_id');
		}

		// Order history comments are printed without escaping in OpenCart.
		$this->model_payment_zibal->addHistoryNote($order_info['order_id'], $order_status_id, htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'));
	}

	private function formatResponse()
	{
		$text = 'HTTP: ' . $this->last_response['http_code'];

		if ($this->last_response['error']) {

			$text .= "\n" . 'cURL error: ' . $this->last_response['error'];
		}

		$text .= "\n" . 'Zibal response: ' . substr($this->last_response['body'], 0, 2000);

		return $text;
	}

	// The browser's return parameters, for notes where Zibal was not called.
	private function formatParams()
	{
		$params = array ();

		foreach (array ('success', 'status', 'trackId', 'orderId') as $key) {

			$value = isset($this->request->get[$key]) ? $this->request->get[$key] : (isset($this->request->post[$key]) ? $this->request->post[$key] : '');

			$params[] = $key . '=' . (is_string($value) ? html_entity_decode(substr($value, 0, 100), ENT_QUOTES, 'UTF-8') : '?');
		}

		return 'Return params: ' . implode(' & ', $params);
	}

	// Order total in Rial as an integer, or false when the order currency is not Rial/Toman.
	private function getAmount($order_info)
	{
		if (!$order_info) {

			return false;
		}

		$currency = strtoupper($order_info['currency_code']);
		$amount   = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

		if (in_array($currency, array ('RLS', 'IRR'))) {

			return (int)round($amount);
		}

		if (in_array($currency, array ('TOM', 'IRT', 'TMN'))) {

			return (int)round($amount * 10);
		}

		return false;
	}

	private function getToken($order_id)
	{
		return hash_hmac('sha256', 'zibal|' . (int)$order_id, (string)$this->config->get('config_encryption'));
	}

	private function checkToken($order_id, $token)
	{
		if (!is_string($order_id) || !is_string($token) || !ctype_digit($order_id) || !$this->config->get('config_encryption')) {

			return false;
		}

		$known = $this->getToken($order_id);

		if (function_exists('hash_equals')) {

			return hash_equals($known, $token);
		}

		if (strlen($known) != strlen($token)) {

			return false;
		}

		$diff = 0;

		for ($i = 0; $i < strlen($known); $i++) {

			$diff |= ord($known[$i]) ^ ord($token[$i]);
		}

		return $diff === 0;
	}

	protected function common($url, $params)
	{
		$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			// Zibal's API only accepts JSON bodies; form-encoded requests get HTTP 500 / result -1.
			curl_setopt($ch, CURLOPT_HTTPHEADER, array ('Content-Type: application/json', 'Accept: application/json'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));

			$response = curl_exec($ch);
			$error    = curl_errno($ch);

			$this->last_response = array (

				'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
				'error'     => $error ? $error . ' ' . curl_error($ch) : '',
				'body'      => $response === false ? '' : (string)$response
			);

			curl_close($ch);

			$output = $error ? false : json_decode($response);

			return $output;
	}
}
