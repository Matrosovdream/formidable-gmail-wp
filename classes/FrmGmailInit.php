<?php
class FrmEasypostInit {

    public function __construct() {

        // Admin classes
        $this->include_settings();

        // API class
        $this->include_api();

        // Shortcodes
        $this->include_shortcodes();

        // Migrations
        $this->include_migrations();

        // Models
        $this->include_models();

        // Helpers
        $this->include_helpers();

        // CRON
        $this->include_cron();

        // Hooks
        $this->include_hooks();

        // Filters
        $this->include_filters();

        // Ajax actions
        $this->include_ajax();

        // Formidable Addons
        $this->include_frm_addons();

        // Routes
        $this->include_routes();

        // Webhooks
        $this->include_webhooks();

    }

    private function include_migrations() {

        // Entries cleaner extra tables
        require_once FRM_EAP_BASE_URL.'/classes//migrations/FrmEasypostMigrations.php';

        // Run migrations
        FrmEasypostMigrations::maybe_upgrade();

    }

    private function include_api() {

        // Abstract API
        require_once FRM_EAP_BASE_URL.'/classes/api/FrmEasypostAbstractApi.php';

        // Shipment API
        require_once FRM_EAP_BASE_URL.'/classes/api/FrmEasypostShipmentApi.php';

        // Address API
        require_once FRM_EAP_BASE_URL.'/classes/api/FrmEasypostAddressApi.php';

        // Smarty API
        require_once FRM_EAP_BASE_URL.'/classes/api/Smarty/FrmSmartyApi.php';

    }

    private function include_models() {

        // Abstract model
        require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostAbstractModel.php';

        // Shipment model
        require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostShipmentModel.php';

        // Shipment Status model
        require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostShipmentStatusModel.php';

        // Shipment Address model
        require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostShipmentAddressModel.php';

        // Shipment Parcel model
        require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostShipmentParcelModel.php';

        // Shipment Label model
        require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostShipmentLabelModel.php';

        // Shipment Rate model
        require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostShipmentRateModel.php';

        // Shipment Address corporate
        //require_once FRM_EAP_BASE_URL.'/classes/models/FrmEasypostShipmentAddressCorpModel.php';

    }

    private function include_utils() {

    }

    private function include_helpers() {

        // Shipment Helper
        require_once FRM_EAP_BASE_URL.'/classes/helpers/FrmEasypostShipmentHelper.php';

        // Entry Helper
        require_once FRM_EAP_BASE_URL.'/classes/helpers/FrmEasypostEntryHelper.php';

        // Carrier Helper
        require_once FRM_EAP_BASE_URL.'/classes/helpers/FrmEasypostCarrierHelper.php';

        // Settings Helper
        require_once FRM_EAP_BASE_URL.'/classes/helpers/FrmEasypostSettingsHelper.php';

    }

    private function include_cron() {

        // Abstract cron class
        require_once FRM_EAP_BASE_URL.'/classes/cron/FrmEasypostAbstractCron.php';

        // Shipments cron
        require_once FRM_EAP_BASE_URL.'/classes/cron/FrmEasypostShipmentsCron.php';
        FrmEasypostShipmentsCron::init();

        // Update entry status cron
        require_once FRM_EAP_BASE_URL.'/classes/cron/FrmUpdateEntryStatusCron.php';
        FrmUpdateEntryStatusCron::init();

        // Void shipments cron
        require_once FRM_EAP_BASE_URL.'/classes/cron/FrmVoidShipmentsCron.php';
        FrmVoidShipmentsCron::init();

    }

    private function include_shortcodes() {

        // Refund
        require_once FRM_EAP_BASE_URL.'/shortcodes/admin/create-easypost-shipment.php';

        // List Shipments for an entry
        require_once FRM_EAP_BASE_URL.'/shortcodes/admin/entry-shipments.php';

        // Verify Address for an entry
        require_once FRM_EAP_BASE_URL.'/shortcodes/admin/entry-verify-address.php';

        // Shipment tracking info
        require_once FRM_EAP_BASE_URL.'/shortcodes/admin/shipment-tracking.php';

        // Shipments all
        require_once FRM_EAP_BASE_URL.'/shortcodes/admin/frm-shipments-all.php';

    }

    private function include_hooks() {
        
        // Void shipment ajax
        //require_once FRM_EAP_BASE_URL.'/actions//user/void-shipment.php';

        // Label bought action
        require_once FRM_EAP_BASE_URL.'/actions//admin/frm_easypost_label_bought.php';

        // Create/update Formidable entry 
        require_once FRM_EAP_BASE_URL.'/actions//admin/frm_after_create_update_entry.php';

    }

    private function include_filters() {

        // Pre-create shipment data filter
        require_once FRM_EAP_BASE_URL.'/actions/filters/frm_easypost_shipment_pre_create_data.php';

    }

    private function include_ajax() {

        // Get entry addresses ajax
        require_once FRM_EAP_BASE_URL.'/actions/ajax/easypost_get_entry_addresses.php';

        // Verify address ajax
        require_once FRM_EAP_BASE_URL.'/actions/ajax/easypost_verify_address.php';

        // Entry verify address ajax (Smarty)
        require_once FRM_EAP_BASE_URL.'/actions/ajax/entry_verify_address.php';

        // Calculate rates ajax
        require_once FRM_EAP_BASE_URL.'/actions/ajax/easypost_calculate_rates.php';

        // Create label ajax
        require_once FRM_EAP_BASE_URL.'/actions/ajax/easypost_create_label.php';

        // Void shipment ajax
        require_once FRM_EAP_BASE_URL.'/actions/ajax/easypost_void_shipment.php';

    }

    private function include_frm_addons() {

        // Frm Update Status Rules
        require_once FRM_EAP_BASE_URL.'/classes/FrmAddons/rules/FrmEntryStatusRuleEngine.php';

        // Update entry status on form submission
        require_once FRM_EAP_BASE_URL.'/classes/FrmAddons/FrmUpdateApplyForm.php';

        // Save entry PDF class
        require_once FRM_EAP_BASE_URL.'/classes/FrmAddons/FrmSaveEntryPdf.php';

    }

    private function include_routes() {

        // Register rewrite + query var
        require_once FRM_EAP_BASE_URL.'/routes/frm-entry-pdf.php';

    }

    private function include_webhooks() {

        // EasyPost webhook REST endpoint
        require_once FRM_EAP_BASE_URL.'/webhooks/FrmEasypostWebhookRest.php';
        FrmEasypostWebhookRest::init();

    }

    private function include_settings() {

        require_once FRM_EAP_BASE_URL.'/classes/admin/FrmEasypostAdminAbstract.php';
        require_once FRM_EAP_BASE_URL.'/classes/admin/FrmEasypostAdminSettings.php';
        require_once FRM_EAP_BASE_URL.'/classes/admin/FrmEasypostAdminAddresses.php';

    }

}

new FrmEasypostInit();