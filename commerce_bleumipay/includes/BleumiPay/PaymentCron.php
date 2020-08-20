<?php

/**
 * Payment File Doc Comment
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
 * Payment Class Doc Comment
 *
 * ("Payments Processor") functions
 * Check payment received in Bleumi Pay and update Orders.
 * All payments received after the last time of this job run are applied to the orders
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */
class PaymentCron
{
    /**
     * Order ID
     *
     * @var orderId;
     */
    protected $orderId;

    /**
     * Database Handler
     *
     * @var DBHandler;
     */
    protected $dbHandler;    

    /**
     * API Handler
     *
     * @var APIHandler;
     */
    protected $api;  

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
     * Payment cron main function.
     *
     * @return void
     */
    public function execute()
    {
        $data_source = 'payments-cron';
        $msg = $data_source . ' : Payments cron start';
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        $start_at =  $this->dbHandler->getCronTime('payment_updated_at');
        $msg = $data_source . ' : looking for payment modified after : ' . $start_at;
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        $next_token = '';
        $updated_at = 0;

        do {
            $result =  $this->api->getPayments($start_at, $next_token);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $msg = $data_source . ' : getPayments api request failed. ' . $result[0]['message'] . ' exiting payments-cron.';
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                return $result[0];
            }
            $payments = $result[1]['results'];
            if (is_null($payments)) {
                $msg = $data_source . ' : unable to fetch payments to process';
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                $errorStatus = array(
                    'code' => -1,
                    'message' => 'no payments data found.', 'bleumipay',
                );
                return $errorStatus;
            }
            try {
                $next_token = $result[1]['next_token'];
            } catch (\Exception $e) {
            }
            if (is_null($next_token)) {
                $next_token = '';
            }

            foreach ($payments as $payment) {
                $updated_at = $payment['updated_at'];
                $msg = $data_source . ' : processing payment : ' . $payment['id'] . ' ' . date('Y-m-d H:i:s', $updated_at);
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                $this->syncPayment($payment, $payment['id'], $data_source);
            }
        } while ($next_token !== '');

        if ($updated_at > 0) {
            $updated_at = $updated_at + 1;
            $this->dbHandler->updateRuntime("payment_updated_at",   $updated_at);
            $msg = $data_source . ' : setting payment_updated_at: ' . date('Y-m-d H:i:s', $updated_at);
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        }
    }

    /**
     * Sync Payment
     *
     * @param $payment     Payment to process.
     * @param $payment_id  Payment ID.
     * @param $data_source Cron job identifier.
     *
     * @return void
     */
    public function syncPayment($payment, $payment_id, $data_source)
    {
        $order_id = null;
        $order_status = null;
        $order = $this->dbHandler->getPendingOrder($payment_id);
        if (!is_null($order)) {
            $order_id = $order->order_id;
            $order_status = $order->status;
        }
        if (!empty($order_id)) {
            $bp_hard_error = $this->dbHandler->getMeta($order_id, 'bleumipay_hard_error');
            // If there is a hard error (or) transient error action does not match, return
            $bp_transient_error = $this->dbHandler->getMeta($order_id, 'bleumipay_transient_error');
            $bp_retry_action = $this->dbHandler->getMeta($order_id, 'bleumipay_retry_action');
            if (($bp_hard_error == 'yes') || (($bp_transient_error == 'yes') && ($bp_retry_action != 'syncPayment'))) {
                $msg = 'syncPayment: ' . $data_source . ' ' . $order_id . ' : Skipping, hard error found (or) retry_action mismatch, order retry_action is : ' . $bp_retry_action;
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                return;
            }

            // If already processing completed, no need to sync
            $bp_processing_completed = $this->dbHandler->getMeta($order_id, 'bleumipay_processing_completed');
            if ($bp_processing_completed == 'yes') {
                $msg = 'syncPayment: ' . $data_source . ' : ' . $order_id . ' ' . 'Processing already completed for this order. No further changes possible.';
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                return;
            }

            // Exit payments_cron update if bp_payment_status indicated operations are in progress or completed
            $bp_payment_status = $this->dbHandler->getMeta($order_id, 'bleumipay_payment_status');
            $invalid_bp_statuses = array('settle-in-progress', 'settled', 'settle-failed', 'refund-in-progress', 'refunded', 'refund-failed');
            if (in_array($bp_payment_status, $invalid_bp_statuses)) {
                $msg = 'syncPayment: ' . $data_source . ' : ' . $order_id . ' exiting .. bp_status:' . $bp_payment_status . ' order_status:' . $order_status;
                watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                return;
            }

            // skip payments_cron update if order was sync-ed by orders_cron in recently.
            $bp_data_source = $this->dbHandler->getMeta($order_id, 'bleumipay_data_source');
            $currentTime = strtotime(date("Y-m-d H:i:s")); //server unix time
            
            $date_modified = $order->changed;
            if ($date_modified == 0) {
                $date_modified = $order->created;
            } 

            $minutes = $this->dbHandler->getMinutesDiff($currentTime, $date_modified);
            if ($minutes < $this->dbHandler::CRON_COLLISION_SAFE_MINUTES) {
                if (($data_source === 'payments-cron') && ($bp_data_source === 'orders-cron')) {
                    $msg = 'syncPayment:' . $order_id . ' skipping payment processing at this time as Orders_CRON processed this order recently, will be processing again later' . 'bleumipay';
                    $this->exception->logTransientException($order_id, 'syncPayment', 'E102', $msg);
                    return;
                }
            }

            $addresses = json_encode($payment["addresses"]);
            $msg = ' $addresses ' . $addresses;
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            $this->dbHandler->updateMetaData($order_id, 'bleumipay_addresses', $addresses);
            //Get token balance
            $result = $this->api->getPaymentTokenBalance($order_id, $payment);
            
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                if ($result[0]['code'] == -2) {
                    $this->dbHandler->applyOrderTransition($order, 'multitoken');
                    $msg = $data_source . " : syncPayment : order-id: " . $order_id . " " . $result[0]['message'] . "', order status changed to 'multi_token_payment";
                    watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                } else {
                    $msg = $data_source . " : syncPayment : order-id: " . $order_id . 'get token balance error';
                    watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
                }
                return;
            } else {
                if ($order_status == 'multitoken') {
                    $this->dbHandler->applyOrderTransition($order, 'singletoken');
                }
            }
            $payment_info = $result[1];
            $this->dbHandler->checkBalanceMarkProcessing($order, $payment_info, $data_source);
            $msg = "syncPayment: " . $data_source . " : order-id: " . (string)$order_id . " set to 'processing'";
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        }
    }


}