<?php

/**
 * ExceptionHandler
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

/**
 * ExceptionHandler
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

class ExceptionHandler
{
    protected $dbHandler;

    /**
     * Constructor
     *
     * @return void
     */    
    public function __construct()
    {
        $this->dbHandler = new DBHandler();
    }

    /**
     * Log Exception
     *
     * @param $orders_id    Order ID to log error
     * @param $retry_action The retry action to be performed 
     * @param $code         Error Code
     * @param $message      Error Message
     *
     * @return void
     */
    public function logException($orders_id, $retry_action, $code, $message)
    {
        if ($code == 400) {
            $this->logHardException($orders_id, $retry_action, $code, $message);
        } else {
            $this->logTransientException($orders_id, $retry_action, $code, $message);
        }
    }

    /**
     * Log transient exception for an order
     *
     * @param $orders_id    Order ID to log the transient error
     * @param $retry_action The retry action to be performed 
     * @param $code         Error Code
     * @param $message      Error Message
     *
     * @return void
     */
    public function logTransientException($orders_id, $retry_action, $code, $message)
    {
        $tries_count = 0;
        //Get previous transient errors for this order
        $prev_count = (int) $this->dbHandler->getMeta($orders_id, 'bleumipay_transient_error_count');
        if (isset($prev_count) && !is_null($prev_count)) {
            $tries_count = $prev_count;
        }
        $prev_code = $this->dbHandler->getMeta($orders_id, 'bleumipay_transient_error_code');
        $prev_action = $this->dbHandler->getMeta($orders_id, 'bleumipay_retry_action');
        //If the same error occurs with same retry_action, then inc the retry count
        if (isset($prev_code) && isset($prev_action) && ($prev_code === $code) && ($prev_action === $retry_action)) {
            $tries_count++;
        } else {
            //Else restart count
            $tries_count = 0;
            $this->dbHandler->updateMetaData($orders_id, 'bleumipay_transient_error', 'yes');
            $this->dbHandler->updateMetaData($orders_id, 'bleumipay_transient_error_code', $code);
            $this->dbHandler->updateMetaData($orders_id, 'bleumipay_transient_error_msg', $message);
            if (!is_null($retry_action)) {
                $this->dbHandler->updateMetaData($orders_id, 'bleumipay_retry_action', $retry_action);
            }
        }
        $this->dbHandler->updateMetaData($orders_id, 'bleumipay_transient_error_count', $tries_count);
    }
    
    /**
     * Log hard expection for an order
     *
     * @param $orders_id    Order ID to log the hard error
     * @param $retry_action The retry action to be performed 
     * @param $code         Error Code
     * @param $message      Error Message
     *
     * @return void
     */
    public function logHardException($orders_id, $retry_action, $code, $message)
    {
        $this->dbHandler->updateMetaData($orders_id, 'bleumipay_hard_error',  'yes');
        $this->dbHandler->updateMetaData($orders_id, 'bleumipay_hard_error_code', $code);
        $this->dbHandler->updateMetaData($orders_id, 'bleumipay_hard_error_msg', $message);
        if (!is_null($retry_action)) {
            $this->dbHandler->updateMetaData($orders_id, 'bleumipay_retry_action', $retry_action);
        }
    }

    /**
     * Clear transient error from an order.
     *
     * @param $orders_id Order ID to remove the transient error.
     *
     * @return void
     */
    public function clearTransientError($orders_id)
    {
        $this->dbHandler->deleteMetaData($orders_id, 'bleumipay_transient_error');
        $this->dbHandler->deleteMetaData($orders_id, 'bleumipay_transient_error_code');
        $this->dbHandler->deleteMetaData($orders_id, 'bleumipay_transient_error_msg');
        $this->dbHandler->deleteMetaData($orders_id, 'bleumipay_transient_error_count');
        $this->dbHandler->deleteMetaData($orders_id, 'bleumipay_retry_action');
    }
    
    /**
     * Get previous retry counts
     *
     * @param $orders_id Order ID to get the retry count.
     *
     * @return void
     */
    public function checkRetryCount($orders_id)
    {
        $retry_count = (int) $this->dbHandler->getMeta($orders_id, 'bleumipay_transient_error_count');
        $action = $this->dbHandler->getMeta($orders_id, 'bleumipay_retry_action');
        if ($retry_count > 3) {
            $code = 'E907';
            $msg = 'Retry count exceeded.';
            $this->logHardException($orders_id, $action, $code, $msg);
        }
        return $retry_count;
    }
}