<?php
defined('ABSPATH') || exit();

class Montonio_Postnord_Parcel_Machines extends Montonio_Shipping_Method
{
    protected $max_dimensions = [42, 49, 60]; // lowest to highest (cm)

    public $default_title = 'Postnord parcel machines';
    public $default_max_weight = 20; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init()
    {
        $this->id = 'montonio_postnord_parcel_machines';
        $this->method_title = __('Montonio Postnord parcel machines', 'montonio-for-woocommerce');
        $this->method_description = __('Postnord parcel machines', 'montonio-for-woocommerce');
        $this->supports = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];

        $this->carrier_code = 'postnord';
        $this->type_v2 = 'parcelMachine';
        $this->title = $this->get_option('title', __('Postnord parcel machines', 'montonio-for-woocommerce'));

        if ('Postnord parcel machines' === $this->title) {
            $this->title = __('Postnord parcel machines', 'montonio-for-woocommerce');
        }
    }
}
