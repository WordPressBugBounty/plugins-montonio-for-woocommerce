<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Montonio WooCommerce settings tab.
 *
 * Each Montonio settings page is a section of this tab, e.g.
 * admin.php?page=wc-settings&tab=montonio&section=shipping. The pages are
 * navigated with the Montonio menu, so WooCommerce's own section links are
 * not output.
 *
 * Saving a section fires woocommerce_update_options_montonio_{section}. The
 * API section is a WC_Settings_API form: it has no WooCommerce settings fields,
 * and saves its form on that hook.
 *
 * @since 10.4.1
 */
class WC_Montonio_Settings extends WC_Settings_Page {

    /**
     * Settings pages, by section ID.
     *
     * @since 10.4.1
     * @var array
     */
    private $section_pages;

    /**
     * Constructor
     */
    public function __construct() {
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/admin/class-wc-montonio-api-settings.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/class-wc-montonio-shipping-settings.php';
        require_once WC_MONTONIO_PLUGIN_PATH . '/includes/withdrawals/class-wc-montonio-withdrawals-settings.php';

        $this->id            = 'montonio';
        $this->label         = 'Montonio';
        $this->section_pages = array(
            'api'         => new WC_Montonio_API_Settings(),
            'shipping'    => new WC_Montonio_Shipping_Settings(),
            'withdrawals' => new WC_Montonio_Withdrawals_Settings()
        );

        parent::__construct();
    }

    /**
     * Get the sections of the tab.
     *
     * @return array Section labels, by section ID.
     */
    public function get_sections() {
        $sections = array();

        foreach ( $this->section_pages as $section_id => $page ) {
            $sections[ $section_id ] = $page->label;
        }

        return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections );
    }

    /**
     * Section links are not output: the Montonio menu links to the sections.
     *
     * @return void
     */
    public function output_sections() {}

    /**
     * Get the settings of a section.
     *
     * @param string|null $section_id Section ID, or null for the current section.
     * @return array
     */
    public function get_settings( $section_id = null ) {
        $page = $this->get_section_page( $section_id );

        if ( ! $page || $page instanceof WC_Settings_API ) {
            return array();
        }

        return $page->get_settings();
    }

    /**
     * Get the settings of a section, for WooCommerce 5.5+.
     *
     * @param string $section_id Section ID.
     * @return array
     */
    protected function get_settings_for_section_core( $section_id ) {
        return $this->get_settings( $section_id );
    }

    /**
     * Output the current section.
     *
     * @return void
     */
    public function output() {
        $page = $this->get_section_page();

        if ( $page ) {
            $page->output();
        }
    }

    /**
     * Get the settings page of a section.
     *
     * @param string|null $section_id Section ID, or null for the current section.
     * @return object|null
     */
    private function get_section_page( $section_id = null ) {
        global $current_section;

        if ( null === $section_id ) {
            $section_id = $current_section;
        }

        return isset( $this->section_pages[ $section_id ] ) ? $this->section_pages[ $section_id ] : null;
    }
}
