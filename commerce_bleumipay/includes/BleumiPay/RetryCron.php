<?php
/**
 * Retry Cron
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
 * Retry Cron
 *
 * ("Retry failed transient actions") functions
 * Finds all the orders that failed during data synchronization
 * and re-performs them
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
class RetryCron
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
        $this->paymentCron = new PaymentCron();
        $this->orderCron = new OrderCron();
    }
    
    /**
     * Retry cron main function.
     *
     * @return void
     */
    public function execute()
    {
        $data_source = 'retry-cron';
        $msg = $data_source . ' : looking for orders with transient errors';
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        $retry_orders = $this->dbHandler->getTransientErrorOrders();
        foreach ($retry_orders as $item) {
            $order_id = $item->order_id;
            $order =  $this->dbHandler->getOrder($order_id);
            $action = $this->dbHandler->getMeta($order_id, 'bleumipay_retry_action');
            $msg = $data_source . ': order_id :' . $order_id . ' staring retry action :' . $action;
            watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
            $this->exception->checkRetryCount($order_id);
            switch ($action) {
            case "syncOrder":
                $this->orderCron->syncOrder($order, $data_source);
                break;
            case "syncPayment":
                $this->paymentCron->syncPayment(null, $order_id, $data_source);
                break;
            case "settle":
                $result = $this->api->getPaymentTokenBalance($order);
                if (is_null($result[0]['code'])) {
                    $this->orderCron->settleOrder($order, $result[1], $data_source);
                }
                break;
            case "refund":
                $result = $this->api->getPaymentTokenBalance($order);
                if (is_null($result[0]['code'])) {
                    $this->orderCron->refundOrder($order, $result[1], $data_source);
                }
                break;
            default:
                break;
            }
        }
    }  
}