<?php
/**
 * DBHandler
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
 * DBHandler 
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
class DBHandler
{

    const CRON_COLLISION_SAFE_MINUTES = 10;
    const AWAIT_PAYMENT_MINUTES = 24 * 60;
    
    /**
     * Retrieves the last execution time of the cron job
     *
     * @param $name Column to fetch value for
     *
     * @return string
     */
    public function getCronTime($name)
    {
        $cron_time = date("Y-m-d H:i:s", strtotime("-1 day"));
        $result = db_select('commerce_bleumipay_cron', 'bpc')
            ->fields('bpc', array($name))
            ->condition('bpc.id', '1')
            ->range(0, 1)
            ->execute()
            ->fetchField();
        if (!empty($result)) {
            $cron_time = $result;
        }
        return $cron_time;
    }

    /**
     * Sets the last execution time of the cron job
     *
     * @param $name Column to update in commerce_bleumipay_cron table
     * @param $time UNIX date/time value
     *
     * @return void
     */
    public function updateRuntime($name, $time)
    {
        db_update('commerce_bleumipay_cron') 
            ->fields(array(
                $name => $time
            ))
            ->condition('id', '1')
            ->execute();
    }

    /**
     * Get Order
     *
     * @param $order_id ID of the order to get details 
     *
     * @return object
     */
    public function getOrder($order_id)
    {
        $order = commerce_order_load((int)$order_id);
        return $order;
    }


    /**
     * Get Order Meta Data
     *
     * @param $order_id    ID of the order to get meta data
     * @param $column_name Column Name
     *
     * @return object
     */
    public function getMeta($order_id, $column_name)
    {
        $result = db_select('commerce_bleumipay_order', 'cbo')
            ->fields('cbo', array($column_name))
            ->condition('cbo.order_id', $order_id)
            ->range(0, 1)
            ->execute()
            ->fetchField();
        return $result;
    }

    /**
     * Update Meta Data
     * 
     * @param $order_id     Order ID
     * @param $column_name  Column Name
     * @param $column_value Column Value
     * 
     * @return array
     */
    public function updateMetaData($order_id, $column_name, $column_value = null)
    {
        db_update('commerce_bleumipay_order') 
            ->fields(array(
                $column_name => $column_value
            ))
            ->condition('order_id', $order_id)
            ->execute();
    }

    /**
     * Create Order Meta Data
     * 
     * @param $order_id Order ID
     * 
     * @return array
     */
    public function createOrderMetaData($order_id)
    {
        db_insert('commerce_bleumipay_order')
            ->fields(array(
            'order_id' => $order_id
            ))
            ->execute();     
    }


    /**
     * Delete Order Meta Data
     *
     * @param $order_id    ID of the order to delete
     * @param $column_name Column Name
     *
     * @return void
     */
    public function deleteMetaData($order_id, $column_name)
    {
        return $this->updateMetaData($order_id, $column_name);
    }

    /**
     * Get the (Pending/Awaiting confirmation/Multi Token Payment)
     * order for the order_id.
     *
     * @param $order_id ID of the order to get the details
     *
     * @return object
     */
    public function getPendingOrder($order_id)
    {
        $query = db_select('commerce_order', 'co');
        $query->fields('co', array('order_id'))
            ->condition('co.order_id', $order_id)
            ->condition('co.status', ['pending', 'awaitingconfirm', 'multitoken'], 'IN');
        $found_id = $query->execute()->fetchField();
        $order = commerce_order_load($found_id);
        return $order;
    }

    /**
     * Get all orders that are modified after $updatedTime
     * Usage: The list of orders processed by Orders cron
     *
     * @param $updatedTime Filter criteria - orders that are modified after this value will be returned
     *
     * @return object
     */
    public function getUpdatedOrders($updatedTime)
    {
        $currentTime = strtotime(date("Y-m-d H:i:s"));
        $db_and1 = db_and()
            ->condition('co.changed', $updatedTime, '>=')
            ->condition('co.changed', $currentTime, '<=');
        $db_or1 = db_or()
            ->condition('cbo.bleumipay_processing_completed', 'no')  
            ->condition('cbo.bleumipay_processing_completed', '')
            ->isNull('cbo.bleumipay_processing_completed');
        $query = db_select('commerce_order', 'co');
        //Joins cannot be chained, so they have to be called separately 
        $query->join('commerce_bleumipay_order', 'cbo', 'co.order_id = cbo.order_id');
        $query->fields('co', array('order_id'))
              ->condition('co.status', ['completed', 'canceled'], 'IN')
              ->condition($db_and1)
              ->condition($db_or1)
              ->orderBy('co.changed', 'DESC');
        $result = $query->execute()->fetchAll();      
        return $result;
    }
    
    /**
     * Get all orders with status = $orderStatus
     * Usage: Orders cron to get all orders that are in
     * 'awaiting_confirmation' status to check if
     * they are still awaiting even after 24 hours.
     *
     * @param $status Filter criteria - status value
     * @param $field  The field to filter on. ('bleumipay_payment_status', 'state')
     *
     * @return object
     */
    public function getOrdersForStatus($status, $field)
    {
        $db_or1 = db_or()
            ->condition('cbo.bleumipay_processing_completed', 'no')  
            ->condition('cbo.bleumipay_processing_completed', '')
            ->isNull('cbo.bleumipay_processing_completed');
        $query = db_select('commerce_order', 'co');
        //Joins cannot be chained, so they have to be called separately 
        $query->join('commerce_bleumipay_order', 'cbo', 'co.order_id = cbo.order_id');
        $query->fields('co', array('order_id'));
        if ($field == 'status') {
            $query->condition('co.' . $field, $status);
        } else {
            $query->condition('cbo.' . $field, $status);
        }
        $query->condition('co.status', ['completed', 'canceled'], 'IN')
            ->condition($db_or1)
            ->orderBy('co.changed', 'DESC');
        $result = $query->execute()->fetchAll();
        return $result;
    }    

    /**
     * Get all orders with transient errors.
     * Used by: Retry cron to reprocess such orders
     *
     * @return array
     */
    public function getTransientErrorOrders()
    {
        $db_or1 = db_or()
            ->condition('cbo.bleumipay_processing_completed', 'no')  
            ->condition('cbo.bleumipay_processing_completed', '')
            ->isNull('cbo.bleumipay_processing_completed');
        $query = db_select('commerce_order', 'co');
        //Joins cannot be chained, so they have to be called separately
        $query->join('commerce_bleumipay_order', 'cbo', 'co.order_id = cbo.order_id');
        $query->fields('co', array('order_id'))
              ->condition('cbo.bleumipay_transient_error', 'yes')
              ->condition($db_or1)
              ->orderBy('co.changed', 'DESC');
        $result = $query->execute()->fetchAll();
        return $result;
    }

    /**
     * Apply the order transition
     *
     * @param $order           The order to be transitioned to new state
     * @param $orderTransition The transition to apply to the order
     * 
     * @return bool
     */
    public function applyOrderTransition($order, $orderTransition)
    {   
        $newStatus = null;
        $comments = null;

        switch ($orderTransition) {
            case 'place':
                $newStatus = 'pending';
                $comments = t('Payment pending for the order');
                break;
            case 'confirm':
                $newStatus = 'awaitingconfirm';
                $comments =  t('Payment received for the order, awaiting confirmation');
                break;    
            case 'process':
                $newStatus = 'processing';
                $comments =  t('Payment confirmed for the order');
                break;
            case 'multitoken':
                $newStatus = 'multitoken';
                $comments =  t('Payment made in multiple tokens for the order');
                break;
            case 'singletoken':
                $newStatus = 'pending';
                $comments =  t('Payment received in single token for the order');
                break;
            case 'fail':
                $newStatus = 'paymentfailed';
                $comments =  t('Payment not received even after 24 hours after order placed');
                break;
            default:
                break;
        }
        if (!is_null($newStatus)) {
            commerce_order_status_update($order, $newStatus, FALSE, NULL, $comments);
        }
    }

    /**
     * Update Payment Transaction
     *
     * @param $order The order to be transitioned to new state
     * 
     * @return void
     */    
    public function updatePaymentTransaction($order) {
        $order_id = $order->order_id;
        $msg = "updatePaymentTransaction: order-id: " . (string)$order_id;
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        $transaction_info = db_select('commerce_payment_transaction', 'cpt')
            ->fields('cpt', array('transaction_id'))
            ->condition('cpt.order_id', $order_id)
            ->condition('cpt.payment_method', 'commerce_payment_commerce_bleumipay')
            ->condition('cpt.status', 'pending')
            ->orderBy('cpt.transaction_id', 'DESC')
            ->range(0, 1)
            //->condition('cpt.remote_id', '', '<>')
            //->condition('cpt.remote_status', 'pending')
            ->execute()
            ->fetchAssoc();
        if ($transaction_info && is_array($transaction_info)) {
          $transaction = commerce_payment_transaction_load($transaction_info['transaction_id']);
          $transaction->message = t('Transaction complete.');
          $transaction->status = COMMERCE_PAYMENT_STATUS_SUCCESS;
          //$transaction->remote_status = ..;
          //$transaction->payload = ..;
          commerce_payment_transaction_save($transaction);
        }
    }    

    /**
     * Check Balance and mark order as processing if sufficient balance is found
     *
     * @param $order        The order to be transitioned to new state
     * @param $payment_info Payment Info
     * @param $data_source  Data Source
     * 
     * @return bool
     */
    public function checkBalanceMarkProcessing($order, $payment_info, $data_source) 
    {
        $order_id = $order->order_id;
        $amount = 0;
        try {
            $amount = (float) $payment_info['token_balances'][0]['balance'];
        } catch (\Exception $e) {
        }
        // Get order to wrapper.
        $wrapper = entity_metadata_wrapper('commerce_order', $order);
        // Get order price.
        $order_value = $wrapper->commerce_order_total->amount->value();
        $msg = "checkBalanceMarkProcessing: " . $data_source . " : order-id: " . (string)$order_id . " order_value: " . (string)$order_value;
        watchdog('commerce_bleumipay', $msg, array(), WATCHDOG_INFO);
        if ($amount >= $order_value/100) {
            $this->applyOrderTransition($order, 'process');
            $this->updateMetaData($order_id, 'bleumipay_processing_completed', "no");
            $this->updateMetaData($order_id, 'bleumipay_payment_status', "payment-received");
            $this->updateMetaData($order_id, 'bleumipay_data_source', $data_source);
            $this->updatePaymentTransaction($order);
            return true;
        }
        return false;
    }

    /**
     * Get Minutes Difference - Returns the difference in minutes between 2 datetimes
     *
     * @param $dateTime1 start datetime
     * @param $dateTime2 end datetime
     *
     * @return bool
     */
    public function getMinutesDiff($dateTime1, $dateTime2)
    {
        $minutes = abs($dateTime1 - $dateTime2) / 60;
        return $minutes;
    }

}