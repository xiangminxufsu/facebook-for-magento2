<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 */

namespace Facebook\BusinessExtension\Helper;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;

use FacebookAds\Object\ServerSide\AdsPixelSettings;

class FBEHelper extends AbstractHelper
{
    const MAIN_WEBSITE_STORE = 'Main Website Store';
    const MAIN_STORE = 'Main Store';
    const MAIN_WEBSITE = 'Main Website';

    const FB_GRAPH_BASE_URL = "https://graph.facebook.com/";

    const DELETE_SUCCESS_MESSAGE = "You have successfully deleted Facebook Business Extension.
    The pixel installed on your website is now deleted.";

    const DELETE_FAILURE_MESSAGE = "There was a problem deleting the connection.
      Please try again.";

    const CURRENT_API_VERSION = "v8.0";

    const MODULE_NAME = "Facebook_BusinessExtension";

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * @var \Facebook\BusinessExtension\Model\ConfigFactory
     */
    protected $_configFactory;
    /**
     * @var \Facebook\BusinessExtension\Logger\Logger
     */
    protected $_logger;
    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $_directoryList;
    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    protected $_curl;
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resourceConnection;
    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $_moduleList;

    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        \Facebook\BusinessExtension\Model\ConfigFactory $configFactory,
        \Facebook\BusinessExtension\Logger\Logger $logger,
        \Magento\Framework\App\Filesystem\DirectoryList $directorylist,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Framework\Module\ModuleListInterface $moduleList
    )
    {
        parent::__construct($context);
        $this->_objectManager = $objectManager;
        $this->_storeManager = $storeManager;
        $this->_configFactory = $configFactory;
        $this->_logger = $logger;
        $this->_directoryList = $directorylist;
        $this->_curl = $curl;
        $this->_resourceConnection = $resourceConnection;
        $this->_moduleList = $moduleList;
    }

    public function getPixelID()
    {
        return $this->getConfigValue('fbpixel/id');
    }

    public function getAccessToken()
    {
        return $this->getConfigValue('fbaccess/token');
    }

    public function getMagentoVersion()
    {
        return $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
    }

    public function getPluginVersion()
    {
        return $this->_moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    public function getSource()
    {
        return 'magento2';
    }

    public function getPartnerAgent()
    {
        return sprintf(
            '%s-%s-%s',
            $this->getSource(),
            $this->getMagentoVersion(),
            $this->getPluginVersion()
        );
    }

    public function getUrl($partialURL)
    {
        $urlInterface = $this->getObject('\Magento\Backend\Model\UrlInterface');
        return $urlInterface->getUrl($partialURL);
    }

    public function getBaseUrlMedia()
    {
        return $this->_storeManager->getStore()->getBaseUrl(
            UrlInterface::URL_TYPE_MEDIA,
            $this->maybeUseHTTPS());
    }

    private function maybeUseHTTPS()
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on');
    }

    public function createObject($fullClassName, array $arguments = [])
    {
        return $this->_objectManager->create($fullClassName, $arguments);
    }

    public function getObject($fullClassName)
    {
        return $this->_objectManager->get($fullClassName);
    }

    public static function isValidFBID($id)
    {
        return preg_match("/^\d{1,20}$/", $id) === 1;
    }

    public function getStore($storeId = null)
    {
        return $this->_storeManager->getStore($storeId);
    }

    public function getBaseUrl()
    {
        // Use this function to get a base url respect to host protocol
        return $this->getStore()->getBaseUrl(
            UrlInterface::URL_TYPE_WEB,
            $this->maybeUseHTTPS());
    }

    public function saveConfig($configKey, $configValue)
    {
        try {
            $configRow = $this->_configFactory->create()->load($configKey);
            if ($configRow->getData('config_key')) {
                $configRow->setData('config_value', $configValue);
                $configRow->setData('update_time', time());
            } else {
                $t = time();
                $configRow->setData('config_key', $configKey);
                $configRow->setData('config_value', $configValue);
                $configRow->setData('creation_time', $t);
                $configRow->setData('update_time', $t);
            }
            $configRow->save();
        } catch (\Exception $e) {
            $this->logException($e);
        }
    }

    public function deleteConfig($configKey)
    {
        try {
            $configRow = $this->_configFactory->create()->load($configKey);
            $configRow->delete();
        } catch (\Exception $e) {
            $this->logException($e);
        }
    }

    public function getDefaultStoreID($validity_check = false)
    {
        $store_id = $this->getConfigValue('fbstore/id');
        if (!$validity_check && $store_id) {
            return $store_id;
        }

        try {
            $valid_store_id = false;
            // Check that store_id is valid, if a store gets deleted, we should_log
            // change the store back to the default store
            if ($store_id) {
                $stores = $this->getStores(true);

                foreach ($stores as $store) {
                    if ($store_id === $store->getId()) {
                        $valid_store_id = true;
                        break;
                    }
                }
                // If the store id is invalid, save the default id
                if (!$valid_store_id) {
                    $store_id = $this->getStore()->getId();
                    $this->saveConfig('fbstore/id', $store_id);
                }
            }

            return is_numeric($store_id)
                ? $store_id
                : $this->getStore()->getId();
        } catch (\Exception $e) {
            $this->log('Failed getting store ID, returning default');
            $this->logException($e);
            return ($store_id)
                ? $store_id
                : $this->getStore()->getId();
        }
    }

    public function getStores($withDefault = false, $codeKey = false)
    {
        return $this->_storeManager->getStores($withDefault, $codeKey);
    }

    public function getConfigValue($configKey)
    {
        try {
            $configRow = $this->_configFactory->create()->load($configKey);
        } catch (\Exception $e) {
            $this->logException($e);
            return null;
        }
        return $configRow ? $configRow->getConfigValue() : null;
    }

    public function makeHttpRequest($requestParams, $accessToken = null)
    {
        $response = null;
        if ($accessToken == null) {
            $accessToken = $this->getConfigValue('fbaccess/token');
        }
        try {
            $url = $this->getCatalogBatchAPI($accessToken);
            $params = [
                'access_token' => $accessToken,
                'requests' => json_encode($requestParams),
                'item_type' => 'PRODUCT_ITEM',
            ];
            $this->_curl->post($url, $params);
            $response = $this->_curl->getBody();
        } catch (\Exception $e) {
            $this->logException($e);
        }
        return $response;
    }

    public function getFBEExternalBusinessId()
    {
        $stored_external_id = $this->getConfigValue('fbe/external/id');
        if ($stored_external_id) {
            return $stored_external_id;
        }
        $store_id = $this->_storeManager->getStore()->getId();
        $this->log("Store id---" . $store_id);
        return uniqid('fbe_magento_' . $store_id . '_');
    }

    public function getStoreName()
    {
        $frontendName = $this->getStore()->getFrontendName();
        if ($frontendName !== 'Default') {
            return $frontendName;
        }
        $defaultStoreId = $this->getDefaultStoreID();
        $defaultStoreName = $this->getStore($defaultStoreId)->getGroup()->getName();
        $escapeStrings = array('\r', '\n', '&nbsp;', '\t');
        $defaultStoreName =
            trim(str_replace($escapeStrings, ' ', $defaultStoreName));
        if (!$defaultStoreName) {
            $defaultStoreName = $this->getStore()->getName();
            $defaultStoreName =
                trim(str_replace($escapeStrings, ' ', $defaultStoreName));
        }
        if ($defaultStoreName && $defaultStoreName !== self::MAIN_WEBSITE_STORE
            && $defaultStoreName !== self::MAIN_STORE
            && $defaultStoreName !== self::MAIN_WEBSITE) {
            return $defaultStoreName;
        }
        return parse_url(self::getBaseUrl(), PHP_URL_HOST);
    }

    public function log($info)
    {
        $this->_logger->info($info);
    }

    public function logException(\Exception $e)
    {
        $this->_logger->error($e->getMessage());
        $this->_logger->error($e->getTraceAsString());
        $this->_logger->error($e);
    }

    public function getAPIVersion()
    {
        $accessToken = $this->getAccessToken();
        if ($accessToken == null) {
            $this->log("can't find access token, won't get api update version ");
            return;
        }
        $api_version = null;
        try {

            $configRow = $this->_configFactory->create()->load('fb/api/version');
            $api_version = $configRow ? $configRow->getConfigValue() : null;
            //$this->log("Current api version : ".$api_version);
            $versionLastUpdate = $configRow ? $configRow->getUpdateTime() : null;
            //$this->log("Version last update: ".$versionLastUpdate);
            $is_updated_version = $this->isUpdatedVersion($versionLastUpdate);
            if ($api_version && $is_updated_version) {
                //$this->log("Returning the version already stored in db : ".$api_version);
                return $api_version;
            }
            $this->_curl->addHeader("Authorization", "Bearer " . $accessToken);
            $this->_curl->get(self::FB_GRAPH_BASE_URL . 'api_version');
            //$this->log("The API call: ".self::FB_GRAPH_BASE_URL.'api_version');
            $response = $this->_curl->getBody();
            //$this->log("The API reponse : ".json_encode($response));
            $decode_response = json_decode($response);
            $api_version = $decode_response->api_version;
            //$this->log("The version fetched via API call: ".$api_version);
            $this->saveConfig('fb/api/version', $api_version);

        } catch (\Exception $e) {
            $this->log("Failed to fetch latest api version with error " . $e->getMessage());
        }

        return $api_version ? $api_version : self::CURRENT_API_VERSION;
    }

    /*
     * TODO decide which ids we want to return for commerce feature
     * This function queries FBE assets and other commerce related assets. We have stored most of them during FBE setup,
     * such as BM, Pixel, catalog, profiles, ad_account_id. We might want to store or query ig_profiles,
     * commerce_merchant_settings_id, pages in the future.
     * API dev doc https://developers.facebook.com/docs/marketing-api/fbe/fbe2/guides/get-features
     * Here is one example response, we would expect commerce_merchant_settings_id as well in commerce flow
     * {"data":[{"business_manager_id":"12345","onsite_eligible":false,"pixel_id":"12333","profiles":["112","111"],
     * "ad_account_id":"111","catalog_id":"111","pages":["111"],"instagram_profiles":["111"]}]}
     *  usage: $_bm = $_assets['business_manager_ids'];
     */
    public function QueryFBEInstalls($external_business_id=null)
    {
        if($external_business_id == null){
            $external_business_id = $this->getFBEExternalBusinessId();
        }
        $accessToken = $this->getAccessToken();
        $url_suffix = "/fbe_business/fbe_installs?fbe_external_business_id=".$external_business_id;
        $url = $this::FB_GRAPH_BASE_URL.$this->getAPIVersion().$url_suffix;
        $this->log($url);
        try {
            $this->_curl->addHeader("Authorization", "Bearer " . $accessToken);
            $this->_curl->get($url);
            $response = $this->_curl->getBody();
            $this->log("The FBE Install reponse : ".json_encode($response));
            $decode_response = json_decode($response, true);
            $_assets = $decode_response['data'][0];
        }catch (\Exception $e) {
            $this->log("Failed to query FBEInstalls" . $e->getMessage());
        }
    }

    public function logPixelEvent($pixel_id, $pixel_event)
    {
        $this->log($pixel_event . " event fired for Pixel id : " . $pixel_id);
    }

    public function deleteConfigKeys()
    {
        $response = array();
        $response['success'] = false;
        try {
            $connection = $this->_resourceConnection->getConnection();
            $facebook_config = $this->_resourceConnection->getTableName('facebook_business_extension_config');
            $sql = "DELETE FROM $facebook_config WHERE config_key NOT LIKE 'permanent%' ";
            $connection->query($sql);
            $response['success'] = true;
            $response['message'] = self::DELETE_SUCCESS_MESSAGE;
        } catch (\Exception $e) {
            $this->log($e->getMessage());
            $response['error_message'] = self::DELETE_FAILURE_MESSAGE;
        }
        return $response;
    }

    public function isUpdatedVersion($versionLastUpdate)
    {
        if (!$versionLastUpdate) {
            return null;
        }
        $monthsSinceLastUpdate = 3;
        try {
            $datetime1 = new \DateTime($versionLastUpdate);
            $datetime2 = new \DateTime();
            $interval = date_diff($datetime1, $datetime2);
            $interval_vars = get_object_vars($interval);
            $monthsSinceLastUpdate = $interval_vars['m'];
            $this->log("Months since last update : " . $monthsSinceLastUpdate);
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }
        // Since the previous version is valid for 3 months,
        // I will check to see for the gap to be only 2 months to be safe.
        return $monthsSinceLastUpdate <= 2;
    }

    public function getCatalogBatchAPI($accessToken)
    {
        $catalog_id = $this->getConfigValue('fbe/catalog/id');
        $external_business_id = $this->getFBEExternalBusinessId();
        if ($catalog_id != null) {
            $catalog_path = "/" . $catalog_id . "/items_batch";
        } else {
            $catalog_path = "/fbe_catalog/batch?fbe_external_business_id=" .
                $external_business_id;
        }
        $catalog_batch_api = self::FB_GRAPH_BASE_URL .
            $this->getAPIVersion($accessToken) .
            $catalog_path;
        $this->log("Catalog Batch API - " . $catalog_batch_api);
        return $catalog_batch_api;
    }

    public function getStoreCurrencyCode()
    {
        $store_id = $this->getDefaultStoreID();
        return $this->getStore($store_id)->getCurrentCurrencyCode();
    }

    public function isFBEInstalled()
    {
        $isFbeInstalled = $this->getConfigValue('fbe/installed');
        if ($isFbeInstalled) {
            return 'true';
        }
        return 'false';
    }

    private function fetchAAMSettings($pixelId)
    {
        return AdsPixelSettings::buildFromPixelId($pixelId);
    }

    public function getAAMSettings()
    {
        $settingsAsString = $this->getConfigValue('fbpixel/aam_settings');
        if ($settingsAsString) {
            $settingsAsArray = json_decode($settingsAsString, true);
            if ($settingsAsArray) {
                $settings = new AdsPixelSettings();
                $settings->setPixelId($settingsAsArray['pixelId']);
                $settings->setEnableAutomaticMatching($settingsAsArray['enableAutomaticMatching']);
                $settings->setEnabledAutomaticMatchingFields($settingsAsArray['enabledAutomaticMatchingFields']);
                return $settings;
            }
        }
        return null;
    }

    private function saveAAMSettings($settings)
    {
        $settingsAsArray = array(
            'enableAutomaticMatching' => $settings->getEnableAutomaticMatching(),
            'enabledAutomaticMatchingFields' => $settings->getEnabledAutomaticMatchingFields(),
            'pixelId' => $settings->getPixelId(),
        );
        $settingsAsString = json_encode($settingsAsArray);
        $this->saveConfig('fbpixel/aam_settings', $settingsAsString);
        return $settingsAsString;
    }

    public function fetchAndSaveAAMSettings($pixelId)
    {
        $settings = $this->fetchAAMSettings($pixelId);
        if ($settings) {
            return $this->saveAAMSettings($settings);
        }
        return null;
    }

    // Generates a map of the form : 4 => "Root > Mens > Shoes"
    public function generateCategoryNameMap()
    {
        $categories = $this->getObject(CategoryCollection::class)
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('path')
            ->addAttributeToSelect('is_active')
            ->addAttributeToFilter('is_active', 1);
        $name = [];
        $breadcrumb = [];
        foreach ($categories as $category) {
            $entityId = $category->getId();
            $name[$entityId] = $category->getName();
            $breadcrumb[$entityId] = $category->getPath();
        }
        // Converts the product category paths to human readable form.
        // e.g.  "1/2/3" => "Root > Mens > Shoes"
        foreach ($name as $id => $value) {
            $breadcrumb[$id] = implode(" > ", array_filter(array_map(
                function ($inner_id) use (&$name) {
                    return isset($name[$inner_id]) ? $name[$inner_id] : null;
                },
                explode("/", $breadcrumb[$id]))));
        }
        return $breadcrumb;
    }
}
