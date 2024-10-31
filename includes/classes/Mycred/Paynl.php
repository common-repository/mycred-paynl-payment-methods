<?php

class Mycred_Paynl extends myCRED_Payment_Gateway {

    function __construct($gateway_prefs) {        
        $types = mycred_get_types();
        $default_exchange = array();
        foreach ($types as $type => $label)
            $default_exchange[$type] = 1;

        parent::__construct(array(
            'id' => 'mycred_paynl',
            'label' => 'Pay.nl',
            'defaults' => array(
                'APItoken' => '',
                'serviceId' => '',
                'currency' => 'EUR',
                'item_name' => __('Purchase of myCRED %plural%', 'mycred'),
                'exchange' => $default_exchange
            )
                ), $gateway_prefs);
    }


    /**
     * Buy Handler
     * @since 0.1
     * @version 1.2
     */
    public function buy() {
        if (
                !isset($this->prefs['APItoken']) || empty($this->prefs['APItoken']) ||
                !isset($this->prefs['serviceId']) || empty($this->prefs['serviceId'])
        )
            wp_die(__('Please setup this gateway before attempting to make a purchase!', 'mycred'));


        // Type
        $type = $this->get_point_type();
        $mycred = mycred($type);

        // Amount
        $amount = $mycred->number($_REQUEST['amount']);
        $amount = abs($amount);

        // Get Cost
        $cost = $this->get_cost($amount, $type);

        $to = $this->get_to();
        $from = $this->current_user_id;

        // Revisiting pending payment
        if (isset($_REQUEST['revisit'])) {
            $this->transaction_id = strtoupper($_REQUEST['revisit']);
        } else {
            $post_id = $this->add_pending_payment(array($to, $from, $amount, $cost, $this->prefs['currency'], $type));
            $this->transaction_id = get_the_title($post_id);
        }

        // Thank you page
        $thankyou_url = $this->get_thankyou();

        // Cancel page
        $cancel_url = $this->get_cancelled($this->transaction_id);

        // Item Name
        $item_name = str_replace('%number%', $amount, $this->prefs['item_name']);
        $item_name = $mycred->template_tags_general($item_name);

        // Hidden form fields
//        $hidden_fields = array(
//            'cmd' => '_xclick',
//            'business' => $this->prefs['account'],
//            'item_name' => $item_name,
//            'quantity' => 1,
//            'amount' => $cost,
//            'currency_code' => $this->prefs['currency'],
//            'no_shipping' => 1,
//            'no_note' => 1,
//            'custom' => $this->transaction_id,
//            'return' => $thankyou_url,
//            'notify_url' => $this->callback_url(),
//            'rm' => 2,
//            'cbt' => __('Return to ', 'mycred') . get_bloginfo('name'),
//            'cancel_return' => $cancel_url
//        );

        $startApi = new Pay_Api_Start();
        $startApi->setApiToken($this->prefs['APItoken']);
        $startApi->setServiceId($this->prefs['serviceId']);

        $startApi->setAmount(round($cost * 100));

        $startApi->setCurrency('EUR');
        $startApi->setExchangeUrl($this->callback_url());
        $startApi->setFinishUrl($this->callback_url());
        $startApi->setDescription($this->transaction_id);
        $startApi->setOrderId($this->transaction_id);

        $result = $startApi->doRequest();




        wp_redirect($result['transaction']['paymentURL']);
//        // Generate processing page
//        $this->get_page_header(__('Processing payment &hellip;', 'mycred'));
//        $this->get_page_redirect($hidden_fields, $location);
//        $this->get_page_footer();
        // Exit
        unset($this);
        exit;
    }

    public function returning() {
        if (isset($_REQUEST['mycred_call']) && $_REQUEST['mycred_call'] == 'mycred_paynl') {
            //alleen iets doen als de call voor mij is
            
            $type = '';
            $orderId = '';

            if (!empty($_REQUEST['orderId'])) {
                $type = 'return';
                $orderId = $_REQUEST['orderId'];
            } elseif (!empty($_REQUEST['order_id'])) {
                $type = 'exchange';
                $orderId = $_REQUEST['order_id'];
            } else {
                return;
                //die('unknown request type');
            }
            try{
                $result = $this->processTransaction($orderId);
                $message = 'Transacion status '.$result['status'] ;
            } catch (Exception $ex) {
                $message = $e->getMessage();
            }
            if($type=='return'){
                if ($result['status'] == 'PAID' || $result['status'] == 'PENDING') {
                    $location = $this->get_thankyou();
                } else {
                    //canceled 
                    $location = $this->get_cancelled($result['transaction_id']);
                }
            } else {
                echo "TRUE|".$message;
            }
            
            wp_redirect($location);
            die();
        }
    }

    private function processTransaction($orderId) {
        $apiInfo = new Pay_Api_Info();

        $apiInfo->setApiToken($this->prefs['APItoken']);
        $apiInfo->setServiceId($this->prefs['serviceId']);

        $apiInfo->setTransactionId($orderId);

        $result = $apiInfo->doRequest();

        $trxId = $result['statsDetails']['extra1'];
        $new_call = array();
        // Get Pending Payment
        $pending_post_id = sanitize_key($trxId);
        $pending_payment = $this->get_pending_payment($pending_post_id);

        //bepaal status
        $state = $result['paymentDetails']['state'];
        if ($state < 0) {
            $status = "CANCELED";
        } elseif ($state == 100) {
            $status = "PAID";
        } else {
            $status = "PENDING";
        }

        if ($pending_payment !== false) {
            // als status betaald is gaan we hem verwerken
            $state = $result['paymentDetails']['state'];

            if ($status == 'CANCELED') {
                $new_call[] = sprintf(__('Payment not completed. Received: %s', 'mycred'), $status);
            } elseif ($status == 'PAID') {
                // If account is credited, delete the post and it's comments.
                if ($this->complete_payment($pending_payment, $trxId))
                    $this->trash_pending_payment($trxId);
                else
                    $new_call[] = __('Failed to credit users account.', 'mycred');
            } else {
                $new_call[] = sprintf(__('Payment not completed. Received: %s', 'mycred'), $status);
            }

            // Log Call
            if (!empty($new_call))
                $this->log_call($trxId, $new_call);
        }
        return array('status' => $status, 'transaction_id' => $trxId);
    }

    /**
     * Preferences
     * @since 0.1
     * @version 1.0
     */
    function preferences() {
        $prefs = $this->prefs;
        ?>

        <label class="subheader" for="<?php echo $this->field_id('APItoken'); ?>"><?php _e('API token'); ?></label>
        <ol>
            <li>
                <div class="h2"><input type="text" name="<?php echo $this->field_name('APItoken'); ?>" id="<?php echo $this->field_id('APItoken'); ?>" value="<?php echo $prefs['APItoken']; ?>" class="long" /></div>
            </li>
        </ol>
        <label class="subheader" for="<?php echo $this->field_id('serviceId'); ?>"><?php _e('serviceId'); ?></label>
        <ol>
            <li>
                <div class="h2"><input type="text" name="<?php echo $this->field_name('serviceId'); ?>" id="<?php echo $this->field_id('serviceId'); ?>" value="<?php echo $prefs['serviceId']; ?>" class="long" /></div>
            </li>
        </ol>
        <label class="subheader" for="<?php echo $this->field_id('item_name'); ?>"><?php _e('Item Name', 'mycred'); ?></label>
        <ol>
            <li>
                <div class="h2"><input type="text" name="<?php echo $this->field_name('item_name'); ?>" id="<?php echo $this->field_id('item_name'); ?>" value="<?php echo $prefs['item_name']; ?>" class="long" /></div>
                <span class="description"><?php _e('Description of the item being purchased by the user.', 'mycred'); ?></span>
            </li>
        </ol>
        <label class="subheader"><?php _e('Exchange Rates', 'mycred'); ?></label>
        <ol>
            <?php $this->exchange_rate_setup('EUR'); ?>
        </ol>
       
        <?php
    }

}
