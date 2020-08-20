<?php

/**
 * Order Cron
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

use Drupal\commerce_bleumipay\BleumiPay\APIHandler;
use Drupal\commerce_bleumipay\BleumiPay\DBHandler;
use Drupal\commerce_bleumipay\BleumiPay\ExceptionHandler;

/**
 * Order Cron
 * 
 * ("Orders Updater") functions
 * Actions Order Statuses changes to Bleumi Pay
 * Any status updates in orders is posted to Bleumi Pay by these function
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

class OrderCron
{

    /**
     * Constructor
     * 
     * @return void
     */
    public function __construct()
    {
        $this->api = new APIHandler();
        $this->dbHandler = new DBHandler();
        $this->exception = new ExceptionHandler();
    }
    
    /**
     * Order cron main function
     *
     * @return void
     */
    public function execute()
    {
        $data_source = 'orders-cron';

        $start_at = $this->dbHandler->getCronTime('order_updated_at');
        $msg = $data_source . " : looking for orders modified after : " . $start_at;
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        $orders = $this->dbHandler->getUpdatedOrders($start_at);
        $updated_at = 0;

        if (!empty($orders)) {
            foreach ($orders as $item) {
                $orderId =  $item->order_id;
                $order =  $this->dbHandler->getOrder($orderId);
                $updated_at = $order->changed;
                $msg = $data_source . ' : processing payment :' . (string)$orderId . ' updated_at: ' . date('Y-m-d H:i:s', $updated_at);
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                $this->syncOrder($order, $data_source);
            }
        } else {
            $msg = 'No updated order found.';
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        }

        if ($updated_at != 0) {
            $updated_at = $updated_at + 1;
            $this->dbHandler->updateRuntime("order_updated_at", $updated_at);
            $msg = $data_source . ' : setting order_updated_at: ' . date('Y-m-d H:i:s', $updated_at);
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        }

        //To verify the status of settle-in-progress orders
        $this->verifySettleOperationStatuses($data_source);
        //Fail order that are awaiting payment confirmation after cut-off (24 Hours) time.
        $this->failUnconfirmedPaymentOrders($data_source);
        //To verify the status of refund-in-progress orders
        $this->verifyRefundOperationStatuses($data_source);
        //To ensure balance in all tokens are refunded
        $this->verifyCompleteRefund($data_source);
    }

    /**
     * Sync Order
     *
     * @param object $order       Order to process
     * @param string $data_source Cron job identifier
     *
     * @return void
     */
    public function syncOrder($order, $data_source)
    {
        $order_id = $order->order_id;
        if (empty($order_id)) {
            return;
        }
        $order_status = $order->status;
        $msg = $data_source . " : syncOrder : order-id: " . $order_id . 'status = '. $order_status;
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);

        $order_modified_date = $order->changed;
        if ($order_modified_date == 0) {
            $order_modified_date = $order->created;
        } 

        $bp_hard_error = $this->dbHandler->getMeta($order_id, 'bleumipay_hard_error');
        $bp_transient_error = $this->dbHandler->getMeta($order_id, 'bleumipay_transient_error');
        $bp_retry_action = $this->dbHandler->getMeta($order_id, 'bleumipay_retry_action');

        // If there is a hard error, return
        if (($bp_hard_error == 'yes')) {
            $msg = 'syncOrder: ' . $data_source . ' :' . $order_id . ' ' . 'Skipping, hard error found. ';
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            return;
        }

        // If there is a transient error & retry_action does not match, return
        if ((($bp_transient_error == 'yes') && ($bp_retry_action != 'syncOrder'))) {
            $msg = 'syncOrder: ' . $data_source . ' :' . $order_id . ' ' . 'Skipping, transient error found and retry_action does not match, order retry_action is : ' . $bp_retry_action;
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            return;
        }

        //If Bleumi Pay processing completed, return
        $bp_processing_completed = $this->dbHandler->getMeta($order_id, 'bleumipay_processing_completed');
        if ($bp_processing_completed == 'yes') {
            $msg = 'syncOrder: ' . $data_source . ' :' . $order_id . ' ' . 'Processing already completed for this order. No further changes possible.';
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            return;
        }

        //If order is in settle-in-progress or refund-in-progress, return
        $bp_payment_status = $this->dbHandler->getMeta($order_id, 'bleumipay_payment_status');
        if (($bp_payment_status == 'refund-in-progress') || ($bp_payment_status == 'settle-in-progress')) {
            return;
        }

        $prev_data_source = $this->dbHandler->getMeta($order_id, 'bleumipay_data_source');
        $currentTime = strtotime(date("Y-m-d H:i:s")); //Server Unix time

        if ($order_modified_date > 0) {
            $minutes = $this->dbHandler->getMinutesDiff($currentTime, $order_modified_date);
            if ($minutes < $this->dbHandler::CRON_COLLISION_SAFE_MINUTES) {
                // Skip orders-cron update if order was updated by payments-cron recently.
                if (($data_source === 'orders-cron') && ($prev_data_source === 'payments-cron')) {
                    $msg = 'syncOrder: ' . $data_source . ' :' . $order_id . ' ' . 'Skipping syncOrder at this time as payments-cron updated this order recently, will be re-tried again';
                    watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                    $this->exception->logTransientException($order_id, 'syncOrder', 'E200', $msg);
                    return;
                }
            }
        }

        $result = $this->api->getPaymentTokenBalance($order_id, null);
        $payment_info = $result[1];
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            //If balance of more than 1 token is found, log transient error & return
            if ($result[0]['code'] == -2) {
                $this->dbHandler->applyOrderTransition($order, 'multitoken');
                $msg = $data_source . ' : syncOrder : order-id: ' . $order_id . ' ' . $result[0]['message'] . "', order status changed to 'multi_token_payment' ";
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            } else {
                $msg = $data_source . ' : syncOrder: order-id: ' . $order_id . ' token balance error : ' . $result[0]['message'];
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            }
            return;
        } else {
            if ($order_status == 'multitoken') {
                $this->dbHandler->applyOrderTransition($order, 'singletoken');
                $this->dbHandler->checkBalanceMarkProcessing($order, $payment_info, $data_source);
                $msg = "syncOrder: " . $data_source . " : order-id: " . (string)$order_id . " set to 'processing'";
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            }
        }
        //If no payment amount is found, return

        $amount = 0;
        try {
            $amount = (float) $payment_info['token_balances'][0]['balance'];
        } catch (\Exception $e) {
        }

        if ($amount == 0) {
            $msg = $data_source . ' : syncOrder:' . 'order-id' . $order_id . ' payment is blank.';
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            return;
        }

        $msg = "";
        switch ($order_status) {
        case 'completed':
            $msg = ' settling payment.';
            $this->settleOrder($order, $payment_info, $data_source);
            break;
        case 'canceled':
            $msg = ' refunding payment.';
            $this->refundOrder($order, $payment_info, $data_source);
            break;
        default:
            $msg = ' switch case : unhandled order status: ' . $order_status;
            break;
        }
        $msg1 = $data_source . ' : syncOrder : order-id: ' . $order_id . ' :' . $msg;
        watchdog('commerce_bleumipay', $msg1, array(), WATCHDOG_INFO);
    }

    /**
     * Settle orders and set to settle-in-progress Bleumi Pay status
     *
     * @param $order        Order to settle payment
     * @param $payment_info Payment Information
     * @param $data_source  Cron job identifier
     *
     * @return void
     */
    public function settleOrder($order, $payment_info, $data_source)
    {
        $msg = '';
        $order_id = $order->order_id;
        usleep(300000); // rate limit delay.
        $result = $this->api->settlePayment($payment_info, $order);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = $result[0]['message'];
            $this->exception->logTransientException($order_id, 'syncOrder', 'E103', $msg);
        } else {
            $operation = $result[1];
            if (!is_null($operation['txid'])) {
                //$order->reduce_order_stock(); // Reduce stock levels
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_txid', $operation['txid']);
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_payment_status', 'settle-in-progress');
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_processing_completed', 'no');
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_data_source', $data_source);
                $this->exception->clearTransientError($order_id);
            }
            $msg = 'settlePayment invoked, tx-id is: ' . $operation['txid'];
        }
        $msg1 = $data_source . ' : settleOrder : order-id :' . (string) $order_id . ' ' . $msg;
        watchdog('commerce_bleumipay', $msg1, array(), WATCHDOG_INFO);
    }

    /**
     * Refund Orders and set to refund-in-progress Bleumi Pay status
     *
     * @param $order        Order to refund payment
     * @param $payment_info Payment Information
     * @param $data_source  Cron job identifier
     *
     * @return void
     */
    public function refundOrder($order, $payment_info, $data_source)
    {
        $msg = '';
        usleep(300000); // rate limit delay.
        $order_id = $order->order_id;
        $result = $this->api->refundPayment($payment_info, $order_id);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = $result[0]['message'];
            $this->exception->logTransientException($order_id, 'syncOrder', 'E205', $msg);
        } else {
            $operation = $result[1];
            if (!is_null($operation['txid'])) {
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_txid', $operation['txid']);
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_payment_status', 'refund-in-progress');
                $this->dbHandler->updateMetaData($order_id, 'bleumipay_processing_completed', 'no');
                $this->exception->clearTransientError($order_id);
                $msg = ' refundPayment invoked, tx-id is: ' . $operation['txid'];
            }
        }
        $msg1 = $data_source . ' : refundOrder : ' . (string) $order_id . ' ' . $msg;
        watchdog('commerce_bleumipay', $msg1, array(), WATCHDOG_INFO);
        $this->dbHandler->updateMetaData($order_id, 'bleumipay_data_source', $data_source);
    }

    /**
     * Find Orders which are in refund-in-progress
     * Bleumi Pay status
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function verifyRefundOperationStatuses($data_source)
    {
        $orders = $this->dbHandler->getOrdersForStatus('refund-in-progress', 'bleumipay_payment_status');
        if (!empty($orders)) {
            $operation = "refund";
            $this->api->verifyOperationCompletion($orders, $operation, $data_source);
        }
    }

    /**
     * Fail the orders that are not confirmed even after cut-off time. (24 hour)
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function failUnconfirmedPaymentOrders($data_source)
    {
        $awaiting_status = 'pending';
        $orders = $this->dbHandler->getOrdersForStatus($awaiting_status, 'status'); //5-awaiting_confirmation
        if (!empty($orders)) {
            foreach ($orders as $item) {
                $order_id = $item->order_id;
                $order =  $this->dbHandler->getOrder($order_id);
                $currentTime = strtotime(date("Y-m-d H:i:s")); //Server UNIX time
                $order_updated_date = $orders->changed;
                if ($order_updated_date == 0) {
                    $order_updated_date = $orders->created;
                }
                if ($order_updated_date > 0 ) {
                    $minutes = $this->dbHandler->getMinutesDiff($currentTime, $order_updated_date);
                    if ($minutes > $this->dbHandler::AWAIT_PAYMENT_MINUTES) {
                        $msg = 'failUnconfirmedPaymentOrders: ' . $order_id . ' ' . 'Payment confirmation not received before cut-off time, elapsed minutes: ' . round($minutes, 2);
                        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                        $this->dbHandler->applyOrderTransition($order, 'fail');
                    }
                }
            }
        }
    }

    /**
     * Verify that the refund is complete
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function verifyCompleteRefund($data_source)
    {
        $orders =  $this->dbHandler->getOrdersForStatus('refunded', 'bleumipay_payment_status');
        if (!empty($orders)) {
            foreach ($orders as $item) {
                $order_id = $item->order_id;
                $result = $this->api->getPaymentTokenBalance($order_id, null);
                $payment_info = $result[1];
                $token_balances = array();
                try {
                    $token_balances = $payment_info['token_balances'];
                } catch (\Exception $e) {
                }

                $token_balances_modified = array();
                //All tokens are refunded, can mark the order as processing completed
                if (count($token_balances) == 0) {
                    $this->dbHandler->updateMetaData($order_id, 'bleumipay_processing_completed', 'yes');
                    $msg = 'verifyCompleteRefund: ' . $order_id . ' processing completed. ';
                    watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                    return;
                }
                $next_token = '';
                do {
                    $ops_result = $this->api->listPaymentOperations($order_id);
                    $operations = $ops_result[1]['results'];
                    $next_token = null;
                    try {
                        $next_token = $operations['next_token'];
                    } catch (\Exception $e) {
                    }

                    if (is_null($next_token)) {
                        $next_token = '';
                    }

                    $valid_operations = array('createAndRefundWallet', 'refundWallet');

                    foreach ($token_balances as $token_balance) {
                        $token_balance['refunded'] = 'no';
                        foreach ($operations as $operation) {
                            if (isset($operation['hash']) && (!is_null($operation['hash']))) {
                                if (($operation['inputs']['token'] === $token_balance['addr']) && ($operation['status'] == 'yes') && ($operation['chain'] == $token_balance['chain']) && (in_array($operation['func_name'], $valid_operations))) {
                                    $token_balance['refunded'] = 'yes';
                                    break;
                                }
                            }
                        }
                        array_push($token_balances_modified, $token_balance);
                    }
                } while ($next_token !== '');

                $all_refunded = 'yes';
                foreach ($token_balances_modified as $token_balance) {
                    if ($token_balance['refunded'] === 'no') {
                        $amount = $token_balance['balance'];
                        if (!is_null($amount)) {
                            $payment_info['id'] = $order_id;
                            $item = array(
                                'chain' => $token_balance['chain'],
                                'addr' => $token_balance['addr'],
                                'balance' => $token_balance['balance'],
                            );
                            $payment_info['token_balances'] = array($item);
                            $this->refundOrder($orders, $payment_info, $data_source);
                            $all_refunded = 'no';
                            break;
                        }
                    }
                }
                if ($all_refunded == 'yes') {
                    $this->dbHandler->updateMetaData($order_id, 'bleumipay_processing_completed', 'yes');
                    $msg = 'verifyCompleteRefund: ' . $order_id . ' processing completed.';
                    watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                }
            }
        }
    }

    /**
     * Find Orders which are in bp_payment_status = settle-in-progress
     * and check transaction status
     *
     * @param $data_source Cron job identifier
     *
     * @return void
     */
    public function verifySettleOperationStatuses($data_source)
    {
        $orders = $this->dbHandler->getOrdersForStatus('settle-in-progress', 'bleumipay_payment_status');
        if (!empty($orders)) {
            $operation = "settle";
            $this->api->verifyOperationCompletion($orders, $operation, $data_source);
        }
    }

}