<?php

class ControllerExtensionModuleVk extends Controller
{
    /**
     * @var array
     */
    private $_error = array();

    /**
     * @var string
     */
    protected $access_token;

    /**
     * @var string
     */
    protected $access_token_group;

    /**
     * @var object
     */
    protected $vkApiClient;

    /**
     * @var array
     */
    protected $settings_oath;

    /**
     * ControllerExtensionModuleVk constructor.
     *
     * @param $registry
     */
    public function __construct($registry)
    {
        parent::__construct($registry);

        $this->load->library('vk/vk');
        $this->load->model('setting/setting');

        $this->settings_oath = $this->model_setting_setting->getSetting('vk_oath');
        $this->vkApiClient = $this->vk->getApiClient();
    }

    /**
     * Install method
     *
     * @return void
     */
    public function install()
    {
        $this->load->model('extension/event');
        $this->load->model('extension/vk/tables');

        $this->model_extension_vk_tables->createTables();

        $this->model_setting_setting->editSetting(
            'vk',
            array(
                'vk_status' => 1,
                'vk_country' => array($this->config->get('config_country_id'))
            )
        );

        $this->model_setting_setting->editSetting(
            'vk_event',
            array(
                'vk_event_status' => 0
            )
        );

        $this->model_extension_event->addEvent(
            'vk',
            'catalog/model/checkout/order/addOrderHistory/after',
            'extension/module/vk/editOrder'
        );
    }

    /**
     * Uninstall method
     *
     * @return void
     */
    public function uninstall()
    {
        $this->load->model('extension/event');
        $this->load->model('extension/vk/tables');

        $this->model_extension_vk_tables->unsetTables();
        $this->unsubscribeToVkEvents();

        $this->model_setting_setting->deleteSetting('vk');
        $this->model_setting_setting->deleteSetting('vk_oath');
        $this->model_setting_setting->deleteSetting('vk_settings');
        $this->model_setting_setting->deleteSetting('vk_event');
        $this->model_setting_setting->deleteSetting('vk_event_settings');
        $this->model_extension_event->deleteEvent('vk');
    }

    public function index() {
        $this->load->language('extension/module/vk');
        $this->load->model('extension/vk/references');
        
        $this->document->addScript('/admin/view/javascript/vk.js');
        $this->document->addStyle('/admin/view/stylesheet/vk.css');

        $this->document->setTitle($this->language->get('heading_title'));
        $_data['heading_title'] = $this->language->get('heading_title');
        $_data['header']        = $this->load->controller('common/header');
        $_data['column_left']   = $this->load->controller('common/column_left');
        $_data['footer']        = $this->load->controller('common/footer');

        $_data['breadcrumbs'] = array();
        
        $_data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true)
        );
        
        $_data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_module'),
            'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=module', true)
        );
        
        $_data['breadcrumbs'][] = array(
            'text' => $this->language->get('module_title'),
            'href' => $this->url->link('extension/module/vk', 'token=' . $this->session->data['token'], true)
        );
        
        $_data['action'] = $this->url->link('extension/module/vk', 'token=' . $this->session->data['token'], true);
        $_data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=module', true);
        $_data['button_cancel'] = $this->language->get('button_cancel');

        # Проверяем наличие токена в базе
        if (!isset($this->model_setting_setting->getSetting('vk_oath')['vk_oath_access_token']) 
            || !isset($this->model_setting_setting->getSetting('vk_oath')['vk_oath_access_token_group'])
        ) {
            $_data['text_authorization']    = $this->language->get('text_authorization');
            $_data['text_id_application']   = $this->language->get('text_id_application');
            $_data['text_secret_key']       = $this->language->get('text_secret_key');
            $_data['text_service_key']      = $this->language->get('text_service_key');
            $_data['text_id_group']         = $this->language->get('text_id_group');
            $_data['button_get_token']      = $this->language->get('button_get_token');
            $_data['text_trusted_redirect'] = $this->language->get('text_trusted_redirect');

            # Обработка изменений со страницы авторизации модуля
            if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validateOath()) {
                $this->request->post['vk_oath_back_link'] = $_data['action'];
                $this->request->post['vk_oath_id_group'] = '-' . $this->request->post['vk_oath_id_group'];
                $this->model_setting_setting->editSetting('vk_oath', $this->request->post);
                $this->response->redirect(HTTPS_SERVER . 'vk_oAth.php');
            }

            $_data['link_trusted_redirect'] = HTTPS_SERVER . 'vk_oAth.php';

            if (isset($this->request->post['vk_oath_secret_key'])) {
                $_data['vk_oath_secret_key'] = $this->request->post['vk_oath_secret_key'];
            } else {
                $_data['vk_oath_secret_key'] = '';
            }

            if (isset($this->request->post['vk_oath_id_application'])) {
                $_data['vk_oath_id_application'] = $this->request->post['vk_oath_id_application'];
            } else {
                $_data['vk_oath_id_application'] = '';
            }

            if (isset($this->request->post['vk_oath_id_group'])) {
                $_data['vk_oath_id_group'] = $this->request->post['vk_oath_id_group'];
            } else {
                $_data['vk_oath_id_group'] = '';
            }

            if (isset($this->_error['secret_key'])) {
                $_data['error_secret_key'] = $this->_error['secret_key'];
            } else {
                $_data['error_secret_key'] = '';
            }

            if (isset($this->_error['id_application'])) {
                $_data['error_id_application'] = $this->_error['id_application'];
            } else {
                $_data['error_id_application'] = '';
            }

            if (isset($this->_error['id_group'])) {
                $_data['error_id_group'] = $this->_error['id_group'];
            } else {
                $_data['error_id_group'] = '';
            }

            $this->response->setOutput($this->load->view('extension/module/vk_oAth', $_data));
        } else {
            $_data['vk_event_status'] = $this->model_setting_setting->getSetting('vk_event')['vk_event_status'];
            
            $this->access_token = $this->settings_oath['vk_oath_access_token'];
            $this->access_token_group = $this->settings_oath['vk_oath_access_token_group'];

            # Обработка изменений в настройках модуля
            if ($this->request->server['REQUEST_METHOD'] == 'POST'/* && $this->validate()*/) {
                $this->model_setting_setting->editSetting('vk_settings', $this->request->post);
                $this->response->redirect($_data['action']);
            }
            # Тексты используемые в верстке
            $_data['button_save']                   = $this->language->get('button_save');
            $_data['button_export_offer']           = $this->language->get('button_export_offer');
            $_data['button_import_offer']           = $this->language->get('button_import_offer');
            $_data['button_save_export_offer']      = $this->language->get('button_save_export_offer');
            $_data['text_success_import_offer']     = $this->language->get('text_success_import_offer');
            $_data['text_success_export_offer']     = $this->language->get('text_success_export_offer');
            $_data['button_clear']                  = $this->language->get('button_clear');
            $_data['button_on']                     = $this->language->get('button_on');
            $_data['button_off']                    = $this->language->get('button_off');
            $_data['text_general_tab']              = $this->language->get('text_general_tab');
            $_data['text_references_tab']           = $this->language->get('text_references_tab');
            $_data['text_catalog_tab']              = $this->language->get('text_catalog_tab');
            $_data['text_logs_tab']                 = $this->language->get('text_logs_tab');
            $_data['text_status_legend']            = $this->language->get('text_status_legend');
            $_data['text_vk_event_legend']          = $this->language->get('text_vk_event_legend');
            $_data['text_delivery_legend']          = $this->language->get('text_delivery_legend');
            $_data['text_default_legend']           = $this->language->get('text_default_legend');
            $_data['text_units_legend']             = $this->language->get('text_units_legend');
            $_data['text_load_catalog']             = $this->language->get('text_load_catalog');
            $_data['text_list_product']             = $this->language->get('text_list_product');
            $_data['text_vk_detail']                = $this->language->get('text_vk_detail');
            $_data['text_confirm_log']              = $this->language->get('text_confirm_log');
            $_data['text_status_title']             = $this->language->get('text_status_title');
            $_data['text_event_title']              = $this->language->get('text_event_title');
            $_data['text_delivery_title']           = $this->language->get('text_delivery_title');
            $_data['text_delivery_default_title']   = $this->language->get('text_delivery_default_title');
            $_data['text_payment_default_title']    = $this->language->get('text_payment_default_title');
            $_data['text_units_length_title']       = $this->language->get('text_units_length_title');
            $_data['text_units_weight_title']       = $this->language->get('text_units_weight_title');
            $_data['units_title']                   = $this->language->get('units_title');
            $_data['text_not_delivery']             = $this->language->get('text_not_delivery');
            $_data['text_not_unit']                 = $this->language->get('text_not_unit');
            $_data['text_error_delivery']           = $this->language->get('text_error_delivery');
            $_data['text_error_units_classes']      = $this->language->get('text_error_units_classes');
            $_data['text_error_log']                = $this->language->get('text_error_log');

            $_data['token']     = $this->session->data['token'];
            $_data['catalog']   = $this->request->server['HTTPS'] ? HTTPS_CATALOG : HTTP_CATALOG;

            $_data['clear_vk']  = $this->url->link('extension/module/vk/clear_vk', 'token=' . $this->session->data['token'], true);

            $_data['saved_settings'] = $this->model_setting_setting->getSetting('vk_settings');

            if (isset($this->request->post['vk_code'])) {
                $_data['vk_code'] = $this->request->post['vk_code'];
            } else {
                $_data['vk_code'] = $this->config->get('vk_code');
            }

            if (isset($this->request->post['vk_status'])) {
                $_data['vk_status'] = $this->request->post['vk_status'];
            } else {
                $_data['vk_status'] = $this->config->get('vk_status');
            }
            
            $logFile = $this->checkLogFile(DIR_LOGS . 'vk.log');
            
            if ($logFile !== false) {
                $_data['logs']['vk_log'] = $logFile;
            } else {
                $_data['logs']['vk_error'] = $this->language->get('text_error_log');
            }

            if (file_exists(DIR_LOGS . 'vk_detailed_logs.log')) {
                $_data['logs']['vk_detail'] = str_replace('/admin', '', HTTPS_SERVER . 'system/storage/logs/vk_detailed_logs.log');
            } else {
                $_data['logs']['vk_detail'] = '';
            }

            # Получение списка категорий
            $_data['categories'] = $this->model_extension_vk_references->getCategories();
            
            # Получение массива статусов заказов
            $_data['statuses'] = $this->model_extension_vk_references->getOrderStatuses();
            
            # Получение массива типов доставок
            $_data['delivery'] = $this->model_extension_vk_references->getDeliveryTypes();
            
            // получение массива типов оплат
            $_data['payments'] = $this->model_extension_vk_references->getPaymentTypes();
            
            // получение массива единиц измерения
            $_data['length_classes'] = $this->model_extension_vk_references->getLengthClasses();
            $_data['weight_classes'] = $this->model_extension_vk_references->getWeightClasses();

            $this->response->setOutput($this->load->view('extension/module/vk', $_data));
        }
    }

    /**
     * Validate
     *
     * @return bool
     */
    protected function validateOath() {
        if (!$this->user->hasPermission('modify', 'extension/module/vk')) {
            $this->_error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['vk_oath_secret_key']) {
            $this->_error['secret_key'] = $this->language->get('error_secret_key');
        }

        if (!$this->request->post['vk_oath_id_application'] || !ctype_digit($this->request->post['vk_oath_id_application'])) {
            $this->_error['id_application'] = $this->language->get('error_id_application');
        }

        if (!$this->request->post['vk_oath_id_group'] || !ctype_digit($this->request->post['vk_oath_id_group'])) {
            $this->_error['id_group'] = $this->language->get('error_id_group');
        }

        return !$this->_error;
    }

    /**
     * Check file size
     *
     * @return string
     */
    private function checkLogFile($file)
    {
        if (file_exists($file)) {
            if (filesize($file) < 2097152) {

                return file_get_contents($file, FILE_USE_INCLUDE_PATH, null);
            } else {

                return false;
            }
        } else {

            return '';
        }
    }

    /**
     * Export offers
     */
    public function exportOffer()
    {
        $categories = $this->vk->createAlbum();
        $this->vk->addProducts($categories);
    }

    /**
     * Import offers
     */
    public function importOffer()
    {
        $this->load->model('catalog/category');
        $this->load->model('catalog/product');
        $this->load->model('extension/vk/tables');

        $offset = 0;

        do {
            $productsIteration = $this->vkApiClient->market()->get([
                'owner_id' => (int)$this->settings_oath['vk_oath_id_group'],
                'count' => 200,
                'offset' => $offset
            ]);

            if (count($productsIteration['items']) > 0) {
                foreach ($productsIteration['items'] as $item) {
                    $this->vk->addProduct($item);
                }
            }

            $offset = $offset + 200;
        } while (count($productsIteration['items']) > 0);
    }

    /**
     * Create order in opencart from vk
     */
    public function createOrder()
    {
        $this->load->model('extension/vk/tables');

        $newOrderVkJson = $this->model_extension_vk_tables->orders()->getLastOrder('json_last_event');

        if (file_exists(DIR_APPLICATION . 'model/extension/vk/custom/receive.php')) {
            $this->load->model('extension/vk/custom/receive');
            $order = $this->model_extension_vk_custom_receive->createOrder(json_decode($newOrderVkJson, true));
        } else {
            $this->load->model('extension/vk/receive/receive');
            $order = $this->model_extension_vk_receive_receive->createOrder(json_decode($newOrderVkJson, true));
        }

        $this->model_extension_vk_tables->orders()->updateNewOrder($order);
    }

    /**
     * Edit order in opencart from vk
     */
    public function editOrder()
    {
        $this->load->model('extension/vk/tables');

        $vk_id = $this->model_extension_vk_tables->events()->getLastEvent()['order_vk_id'];

        if (file_exists(DIR_APPLICATION . 'model/extension/vk/custom/receive.php')) {
            $this->load->model('extension/vk/custom/receive');
            $this->model_extension_vk_custom_receive->updateOrder($vk_id);
        } else {
            $this->load->model('extension/vk/receive/receive');
            $this->model_extension_vk_receive_receive->updateOrder($vk_id);
        }

        $this->model_extension_vk_tables->events()->delete($vk_id);
    }

    /**
     * Subscribe to Vk events
     */
    public function subscribeToVkEvents()
    {
        $this->load->model('setting/setting');

        $this->getCallbackConfirmationCode();
        $serverId = $this->addCallbackServer();

        sleep(3);

        $this->setCallbackSettings($serverId);

        $this->model_setting_setting->editSetting(
            'vk_event_settings',
            array(
                'vk_event_settings_server_id' => $serverId
            )
        );

        $this->model_setting_setting->editSetting(
            'vk_event',
            array(
                'vk_event_status' => 1
            )
        );
    }

    /**
     * Set callback server settings
     *
     * @param int $serverId
     */
    private function setCallbackSettings($serverId)
    {
        $this->vkApiClient->groups()->setCallbackSettings(
            array(
                'group_id' => ltrim($this->settings_oath['vk_oath_id_group'], '-'),
                'server_id' => $serverId,
                'api_version' => '5.124',
                'market_order_new' => 1,
                'market_order_edit' => 1
            )
        );
    }

    /**
     * Delete callback api server
     */
    public function unsubscribeToVkEvents()
    {
        $this->load->model('setting/setting');

        $this->vkApiClient->groups()->deleteCallbackServer(
            array(
                'group_id' => ltrim($this->settings_oath['vk_oath_id_group'], '-'),
                'server_id' => $this->model_setting_setting->getSetting('vk_event_settings')['vk_event_settings_server_id']
            )
        );

        $this->model_setting_setting->editSetting(
            'vk_event',
            array(
                'vk_event_status' => 0
            )
        );

        $this->model_setting_setting->deleteSetting('vk_event_settings');
    }

    /**
     * Add callback api server
     *
     * @return int
     */
    private function addCallbackServer()
    {
        $response = $this->vkApiClient->groups()->addCallbackServer(
            array(
                'group_id' => ltrim($this->settings_oath['vk_oath_id_group'], '-'),
                'url' => HTTPS_CATALOG . 'system/vk_events/vkEvents.php',
                'title' => substr(str_replace(['https', 'http', ':', '/'], '', HTTPS_CATALOG), 0, 14),
                'secret_key' => $this->settings_oath['vk_oath_secret_key']
            )
        );

        return $response['server_id'];
    }

    /**
     * Get code for subscribe to vk events
     *
     * @return string
     */
    private function getCallbackConfirmationCode()
    {
        $this->load->model('setting/setting');

        $response = $this->vkApiClient->groups()->getCallbackConfirmationCode(
            array(
                'group_id' => ltrim($this->settings_oath['vk_oath_id_group'], '-')
            )
        );

        $this->model_setting_setting->editSetting(
            'vk_event_code',
            array(
                'vk_event_code' => $response['code']
            )
        );

        return $response['code'];
    }

    /**
     * Clear vk log file
     *
     * @return void
     */
    public function clear_vk()
    {
        if ($this->user->hasPermission('modify', 'extension/module/vk')) {
            $file = DIR_LOGS . 'vk.log';

            $handle = fopen($file, 'w+');

            fclose($handle);
        }

        $this->response->redirect($this->url->link('extension/module/vk', 'token=' . $this->session->data['token'], true));
    }
}