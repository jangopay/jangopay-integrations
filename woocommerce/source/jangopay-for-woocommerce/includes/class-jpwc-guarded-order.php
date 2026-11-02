<?php
if (!defined('ABSPATH')) { exit; }
/**
 * Standard WooCommerce shop orders returned through wc_get_order().
 *
 * Guard before WC_Order::save() so an admission failure cannot be swallowed
 * by WC_Abstract_Order::save() and replayed as a phantom status transition.
 * This class intentionally does not override payment_complete/status business
 * logic; all valid saves and native WooCommerce hooks remain in core.
 */
trait JPWC_Guarded_Order_Save {
    public function save() {
        if (!$this->get_id())
            return parent::save();
        // Lock BEFORE asking whether a JangoPay ledger exists. Even an order
        // with no ledger can race with the first payment reservation while WC
        // is inside parent::save() and before the datastore writes anything.
        JPWC_Order_Guard::beforeSave($this);
        try {
            $result=parent::save();
            JPWC_Order_Guard::assertCommitted($this);
            return $result;
        } finally {
            JPWC_Order_Guard::afterSave($this);
        }
    }
    protected function status_transition() {
        // WooCommerce can silently handle a datastore Exception and then run
        // status_transition anyway. Stop all state hooks if data was not saved.
        JPWC_Order_Guard::assertCommitted($this);
        parent::status_transition();
    }
}

class JPWC_Guarded_Order extends WC_Order implements JPWC_Guarded_Order_Contract {
    use JPWC_Guarded_Order_Save;
}
