<?php

/**
 * APIHandler
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */

namespace Drupal\commerce_bleumipay\BleumiPay;

require_once __DIR__ . '/vendor/autoload.php';

use Drupal\commerce_bleumipay\BleumiPay\DBHandler;
use Drupal\commerce_bleumipay\BleumiPay\ExceptionHandler;
/**
 * APIHandler 
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */

class APIHandler
{
    protected $payment_instance;
    protected $HC_instance;
    protected $dbHandler;
    protected $exception;
    /**
     * Constructor
     *
     * @param $apiKey API key
     * 
     * @return void
     */
    public function __construct()
    {
        $apiKey = '';
        $key = 'commerce_bleumipay|commerce_payment_commerce_bleumipay';
        $payment_method = commerce_payment_method_instance_load($key);
        if (isset($payment_method['settings']['api_key'])) {
            $apiKey = $payment_method['settings']['api_key'];
        }
        $bleumiPayConfig = \Bleumi\Pay\Configuration::getDefaultConfiguration()->setApiKey('x-api-key', $apiKey);
        $this->payment_instance = new \Bleumi\Pay\Api\PaymentsApi(new \GuzzleHttp\Client(), $bleumiPayConfig);
        $this->HC_instance = new \Bleumi\Pay\Api\HostedCheckoutsApi(new \GuzzleHttp\Client(), $bleumiPayConfig);
        $this->dbHandler = new DBHandler();
        $this->exception = new ExceptionHandler();
    }

    /**
     * Create payment in Bleumi Pay for the given order
     *
     * @param $data Object with data for payment creation
     *
     * @return object
     */
    public function create($data)
    {
        try {
            $createReq = new \Bleumi\Pay\Model\CreateCheckoutUrlRequest();
            $createReq->setId((string) $data["id"]);
            $createReq->setCurrency((string) $data['currency']);
            $createReq->setAmount((string) $data['total']);
            $createReq->setSuccessUrl($data["success"]);
            $createReq->setCancelUrl($data["cancel"]);
            $createReq->setBase64Transform(true);
            $result = $this->HC_instance->createCheckoutUrl($createReq);
            return $result;
        } catch (\Exception $e) {
            $msg = 'create_payment: failed response: ' . $e->getMessage();
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
        }
    }

    /**
     * Validate Payment Completion Parameters.
     *
     * @param $params HMAC parameters
     *
     * @return bool
     */
    public function validatePayment($params)
    {
        $result = null;
        $errorStatus = array();
        try {
            $validateCheckoutRequest = new \Bleumi\Pay\Model\ValidateCheckoutRequest();
            $validateCheckoutRequest->setHmacAlg($params['hmac_alg']);
            $validateCheckoutRequest->setHmacInput($params['hmac_input']);
            $validateCheckoutRequest->setHmacKeyId($params['hmac_keyId']);
            $validateCheckoutRequest->setHmacValue($params['hmac_value']);
            $result = $this->HC_instance->validateCheckoutPayment($validateCheckoutRequest);
        } catch (\Exception $e) {
            $msg = 'validatePayment: failed, response: ' . $e->getMessage();
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }


    /**
     * Get Tokens
     *
     * @return array
     */
    public function getTokens()
    {
        $result = array();

        try {
            $result = $this->HC_instance->listTokens();
        } catch (\Exception $e) {
            return false;
        }
        return $result;
    }

    /**
     * Retrieves the payment details for the order_id from Bleumi Pay
     *
     * @param $start_at   Filter criteria to fetch payments after this UNIX timestamp 
     * @param $next_token The token to get next page of results
     *
     * @return array
     */
    public function getPayments($start_at, $next_token)
    {
        $msg = 'Get Payment Method started ';
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);

        $result = null;
        $errorStatus = array();
        $next_token = $next_token;
        $sort_by = "updatedAt";
        $sort_order = "ascending";

        try {
            $result = $this->payment_instance->listPayments($next_token, $sort_by, $sort_order, (string) $start_at);
        } catch (\Exception $e) {
            $msg = 'getPayments: failed, response: ' . $e->getMessage();
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * Get Payment Token Balance - from payment object
     * Parses the payment object which uses dictionaries
     *
     * {
     *  "id": "535",
     *  "addresses": {
     *    "ethereum": {
     *      "goerli": {
     *        "addr": "0xbead07d152c64159190842ec1d6144f1a4a6cae9"
     *      }
     *    }
     *  },
     *  "balances": {
     *    "ethereum": {
     *      "goerli": {
     *        "0x115615dbd0f835344725146fa6343219315f15e5": {
     *          "blockNum": "1871014",
     *          "token_balance": "10000000",
     *          "balance": "10",
     *          "token_decimals": 6
     *        }
     *      }
     *    }
     *  },
     *    "createdAt": 1577086517,
     *    "updatedAt": 1577086771
     * }
     *
     * @param $order_id Order ID
     * @param $payment  Payment Object
     *
     * @return array
     */
    public function getPaymentTokenBalance($order_id, $payment = null)
    {
        $chain = '';
        $addr = '';
        $token_balances = array();
        $payment_info = array();
        $errorStatus = array();
        //Call getPayment API to set $payment if found null.
        if (is_null($payment)) {
            $result = $this->getPayment($order_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $msg = 'getPaymentTokenBalance: order-id :' . $order_id . ' getPayment api failed : ' . $result[0]['message'];
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_ERROR);
                $errorStatus = array(
                    'code' => -1,
                    'message' => 'get payment details failed ',
                );
                return array($errorStatus, $payment_info);
            }
            $payment = $result[1];
        }

        // If still not payment data is found, return error
        if (is_null($payment)) {
            $errorStatus = array(
                'code' => -1,
                'message' => 'no payment details found ',
            );
            return array($errorStatus, $payment_info);
        }

        $payment_info['id'] = $payment['id'];
        $payment_info['addresses'] = $payment['addresses'];
        $payment_info['balances'] = $payment['balances'];
        $payment_info['date_purchased'] = $payment['created_at'];
        $payment_info['last_modified'] = $payment['updated_at'];

        if ($this->isMultiTokenPayment($payment)) {
            $msg = 'More than one token balance found';
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            $errorStatus['code'] = -2;
            $errorStatus['message'] = $msg;
            return array($errorStatus, $payment_info);
        }

        $order = $this->dbHandler->getOrder($order_id);
        // Get order to wrapper.
        $wrapper = entity_metadata_wrapper('commerce_order', $order);
        $order_total = $wrapper->commerce_order_total->value();
        $storeCurrency = $order_total['currency_code'];

        $result = $this->listTokens($storeCurrency);

        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = '[getPaymentTokenBalance: order-id :' . $order_id . ' listTokens api failed : ' . $result[0]['message'];
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_ERROR);
            return array($result[0], $payment_info);
        }
        $tokens = $result[1];
        if (count($tokens) > 0) {
            foreach ($tokens as $token) {
                $network = $token['network'];
                $chain = $token['chain'];
                $addr = $token['addr'];
                $token_balance = null;
                try {
                    if (!is_null($network) && !is_null($chain) && !is_null($addr)) {
                        $token_balance = $payment['balances'][$network][$chain][$addr];
                    }
                } catch (\Exception $e) {
                    continue;
                }
                /*{
                "balance": "0",
                "token_decimals": 6,
                "blockNum": "1896563",
                "token_balance": "0"
                }*/
                if (!is_null($token_balance['balance'])) {
                    $balance = (float) $token_balance['balance'];
                    if ($balance > 0) {
                        $item = array();
                        $item['network'] = $network;
                        $item['chain'] = $chain;
                        $item['addr'] = $addr;
                        $item['balance'] = $token_balance['balance'];
                        $item['token_decimals'] = $token_balance['token_decimals'];
                        $item['blockNum'] = $token_balance['blockNum'];
                        $item['token_balance'] = $token_balance['token_balance'];
                        array_push($token_balances, $item);
                    }
                }
            }
        }
        $ret_token_balances = $this->ignoreALGO($token_balances);
        $balance_count = count($ret_token_balances);

        if ($balance_count > 0) {
            $payment_info['token_balances'] = $ret_token_balances;
            if ($balance_count > 1) {
                $msg = 'More than one token balance found';
                $msg1 = 'getPaymentTokenBalance: order-id :' . $order_id . ', balance_count: ' . $balance_count . ', ' . $msg;
                watchdog('commerce_bleumipay', $msg1, array(), WATCHDOG_ERROR);
                $errorStatus['code'] = -2;
                $errorStatus['message'] = $msg;
            }
        } else {
            $msg = 'getPaymentTokenBalance: order-id :' . $order_id . ', no token balance found ';
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        }

        return array($errorStatus, $payment_info);
    }

    /**
     * Retrieves the payment details for the order_id from Bleumi Pay
     *
     * @param $order_id ID of the Bleumi Pay payment
     *
     * @return array
     */
    public function getPayment($order_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPayment($order_id);
        } catch (\Exception $e) {
            $msg = 'getPayment: failed order-id:' . $order_id;
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_ERROR);
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * To check whether payment is made using multiple tokens
     * It is possible that user could have made payment to
     * the wallet address using a different token
     * Output is false
     *         if balance>0 is found
     *         for more than 1 token
     *
     * @param $payment Payment Object
     *
     * @return bool
     */
    public function isMultiTokenPayment($payment)
    {
        $networks = array('ethereum', 'algorand', 'rsk');
        $token_balances = array();
        $chain_token_balances = null;
        foreach ($networks as $network) {
            $chains = array();
            if ($network === 'ethereum') {
                $chains = array('mainnet', 'goerli', 'xdai_testnet', 'xdai');
            } else if ($network === 'algorand') {
                $chains = array('alg_mainnet', 'alg_testnet');
            } else if ($network === 'rsk') {
                $chains = array('rsk', 'rsk_testnet');
            }
            foreach ($chains as $chain) {
                try {
                    $chain_token_balances = $payment['balances'][$network][$chain];
                } catch (\Exception $e) {
                }
                if (!is_null($chain_token_balances)) {
                    foreach ($chain_token_balances as $addr => $token_balance) {
                        $balance = (float) $token_balance['balance'];
                        if ($balance > 0) {
                            $item = array();
                            $item['network'] = $network;
                            $item['chain'] = $chain;
                            $item['addr'] = $addr;
                            $item['balance'] = $token_balance['balance'];
                            $item['token_decimals'] = $token_balance['token_decimals'];
                            $item['blockNum'] = $token_balance['blockNum'];
                            $item['token_balance'] = $token_balance['token_balance'];
                            array_push($token_balances, $item);
                        }
                    }
                }
            }
        }
        $ret_token_balances = $this->ignoreALGO($token_balances);
        return (count($ret_token_balances) > 1);
    }

    /**
     * To ignore ALGO balance when Algorand ASA payment is made
     *
     * @param array $token_balances Array of token balances
     *
     * @return array A new array which does not contain the ALGO token balance if any Algorand ASA token balance is found
     */
    public function ignoreALGO($token_balances)
    {
        $algo_token_found = false;
        $ret_token_balances = array();
        foreach ($token_balances as $item) {
            if (($item['network'] === 'algorand') && ($item['addr'] !== 'ALGO')) {
                $algo_token_found = true;
            }
        }
        foreach ($token_balances as $item) {
            if ($item['network'] === 'algorand') {
                if (($algo_token_found) && ($item['addr'] !== 'ALGO')) {
                    array_push($ret_token_balances, $item);
                }
            } else {
                array_push($ret_token_balances, $item);
            }
        }
        return $ret_token_balances;
    }

    /**
     * List of Payment Operations
     *
     * @param $id         ID of the Bleumi Pay payment
     * @param $next_token The token to get next page of results
     *
     * @return array
     */
    public function listPaymentOperations($id, $next_token = null)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->listPaymentOperations($id, $next_token);
        } catch (\Exception $e) {
            $msg = 'listPaymentOperations: failed : ' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_ERROR);
        }
        return array($errorStatus, $result);
    }

    /**
     * List Tokens
     * 
     * @param $storeCurrency Currency to get tokens for
     *
     * @return array
     */
    public function listTokens($storeCurrency)
    {
        $result = array();
        $errorStatus = array();
        try {
            $tokens = $this->HC_instance->listTokens();
            foreach ($tokens as $item) {
                if ($item['currency'] === $storeCurrency) {
                    array_push($result, $item);
                }
            }
        } catch (\Exception $e) {
            $msg = 'listTokens: failed, response: response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * Verify Payment operation completion status.
     * 
     * @param $orders      Orders
     * @param $operation   Operation
     * @param $data_source Data Source
     *
     * @return void
     */
    public function verifyOperationCompletion($orders, $operation, $data_source)
    {

        $completion_status = '';
        $op_failed_status = '';
        if ($operation === 'settle') {
            $completion_status = 'settled';
            $op_failed_status = 'settle-failed';
        } else if ($operation === 'refund') {
            $completion_status = 'refunded';
            $op_failed_status = 'refund-failed';
        }

        foreach ($orders as $item) {
            $order_id = $item->order_id;
            $order =  $this->dbHandler->getOrder($order_id);
            $tx_id = $this->dbHandler->getMeta($order_id, 'bleumipay_txid');
            if (is_null($tx_id)) {
                $msg = 'verifyOperationCompletion: order-id :' . $order_id . ' tx-id is not set.';
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                continue;
            }
            //For such orders perform get operation & check whether status has become 'true'
            $result = $this->getPaymentOperation($order_id, $tx_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $msg = 'verifyOperationCompletion: order-id :' . $order_id . ' getPaymentOperation api request failed: ' . $result[0]['message'];
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                continue;
            }
            $status = $result[1]['status'];
            $txHash = $result[1]['hash'];
            $chain = $result[1]['chain'];
            if (!is_null($status)) {
                if ($status == 'yes') {
                    //$note = 'Tx hash for Bleumi Pay transfer ' . $txHash . ' Transaction Link : ' . $this->utils->getTransactionLink($txHash, $chain);
                    //$this->utils->addOrderNote($order_id, $note, true);
                    $this->dbHandler->updateMetaData($order_id, 'bleumipay_payment_status', $completion_status);
                    if ($operation === 'settle') {
                        $this->dbHandler->updateMetaData($order_id, 'bleumipay_processing_completed', 'yes');
                    }
                } else {
                    $msg = 'payment operation failed';
                    $this->dbHandler->updateMetaData($order_id, 'bleumipay_payment_status', $op_failed_status);
                    if ($operation === 'settle') {
                        //Settle failure will be retried again & again
                        $this->exception->logTransientException($order_id, $operation, 'E908', $msg);
                    } else {
                        //Refund failure will not be processed again
                        $this->exception->logHardException($order_id, $operation, 'E909', $msg);
                    }
                }
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_data_source', $data_source);
            }
        }
    }

    /**
     * Retrieves the payment operation details for the id, tx_id
     * from Bleumi Pay
     *
     * @param $id    ID of the Bleumi Pay payment
     * @param $tx_id Tranaction ID of the Bleumi Pay payment Operation
     *
     * @return array
     */
    public function getPaymentOperation($id, $tx_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPaymentOperation($id, $tx_id);
        } catch (\Exception $e) {
            $msg = 'getPaymentOperation: failed : payment-id: ' . $id . ' tx_id: ' . json_encode($tx_id) . ' response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->exception->logException($id, 'getPaymentOperation', $e->getCode(), $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * Settle payment in Bleumi Pay for the given order
     *
     * @param $payment_info Payment information object
     * @param $order        Store Order object
     *
     * @return array
     */
    public function settlePayment($payment_info, $order)
    {

        $result = null;
        $errorStatus = array();
        $id = $payment_info['id'];
        $tokenBalance = $payment_info['token_balances'][0];
        $token = $tokenBalance['addr'];
        $paymentSettleRequest = new \Bleumi\Pay\Model\PaymentSettleRequest();
        // Get order to wrapper.
        $wrapper = entity_metadata_wrapper('commerce_order', $order);
        // Get order price.
        $amount = ($wrapper->commerce_order_total->amount->value())/100;
        $msg = 'settlePayment : order total: ' . $amount;
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_ERROR);
        $strAmount = sprintf("%f", $amount);
        $msg = 'settlePayment : order total: strAmount: ' . $strAmount;
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_ERROR);
        $paymentSettleRequest->setAmount($strAmount);
        $paymentSettleRequest->setToken($token);
        try {
            $result = $this->payment_instance->settlePayment($paymentSettleRequest, $id, $tokenBalance['chain']);
            $order_id = $order->order_id;
            $this->exception->clearTransientError($order_id);
        } catch (\Exception $e) {
            $msg = 'settlePayment --Exception--' . $e->getMessage();
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            $msg = 'settlePayment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_ERROR);
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * Refund payment in Bleumi Pay for the given order
     *
     * @param $payment_info Payment information object
     * @param $order_id     Order ID
     *
     * @return array
     */
    public function refundPayment($payment_info, $order_id)
    {
        $result = null;
        $errorStatus = array();
        $id = $payment_info['id'];
        try {
            $tokenBalance = $payment_info['token_balances'][0];
            $amount = (float) $tokenBalance['balance'];
            $token = $tokenBalance['addr'];

            if ($amount > 0) {
                $paymentRefundRequest = new \Bleumi\Pay\Model\PaymentRefundRequest();
                $paymentRefundRequest->setToken($token);
                $result = $this->payment_instance->refundPayment($paymentRefundRequest, $id, $tokenBalance['chain']);
            }
            $this->exception->clearTransientError($order_id);
        } catch (\Exception $e) {
            $msg = 'refundPayment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

}