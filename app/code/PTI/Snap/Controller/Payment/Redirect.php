<?php
namespace PTI\Snap\Controller\Payment;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;

$object_manager = \Magento\Framework\App\ObjectManager::getInstance();
$filesystem = $object_manager->get('Magento\Framework\Filesystem');
$root = $filesystem->getDirectoryRead(DirectoryList::ROOT);
$lib_file = $root->getAbsolutePath('lib/internal/veritrans-php/Veritrans.php');
require_once($lib_file);

class Redirect extends \Magento\Framework\App\Action\Action
{
    /** @var \Magento\Framework\View\Result\PageFactory  */
    protected $_checkoutSession;
    protected $_logger;
    protected $_coreSession;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Session\SessionManagerInterface $coreSession
    ){
        parent::__construct($context);
        $this->_coreSession = $coreSession;
    }

    public function execute()
    {
        $om = $this->_objectManager;
        $session = $om->get('Magento\Checkout\Model\Session');
//        $quote = $session->getQuote();
        $quote2 = $session->getLastRealOrder();
//       echo 'QUOTE ID : '.$quote->getId();


        $vtConfig = $om->get('Veritrans\Veritrans_Config');
        $config = $om->get('Magento\Framework\App\Config\ScopeConfigInterface');

//        $orderIncrementId = $quote->getReservedOrderId();
        $orderIncrementId = $quote2->getIncrementId();
        $orderId = $quote2->getId();
        $quote = $om->create('Magento\Sales\Model\Order')->load($orderId);

        $isProduction = $config->getValue('payment/snap/is_production', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)=='1'?true:false;
        $vtConfig->setIsProduction($isProduction);

        $is3ds = $config->getValue('payment/snap/is_3ds', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)=='1'?true:false;
        $vtConfig->setIs3ds($is3ds); // selalu true

        $title = $config->getValue('payment/snap/title', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $serverKey = $config->getValue('payment/snap/server_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
//        echo $title;exit();
        $oneClick = $config->getValue('payment/snap/one_click', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $customExpiry = $config->getValue('payment/snap/custom_expiry', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        $vtConfig->setServerKey($serverKey);
//        $vtConfig->setIs3Ds(false);
        $vtConfig->setIsSanitized(false);

        $transaction_details = array();
        $transaction_details['order_id'] = $orderIncrementId;
        $this->setValue($orderIncrementId);

        $order_billing_address = $quote->getBillingAddress();
    
        $billing_address = array();
        $billing_address['first_name']   = $order_billing_address->getFirstname();
        $billing_address['last_name']    = $order_billing_address->getLastname();
        $billing_address['address']      = $order_billing_address->getStreet()[0];
        $billing_address['city']         = $order_billing_address->getCity();
        $billing_address['postal_code']  = $order_billing_address->getPostcode();
        $billing_address['country_code'] = $this->convert_country_code($order_billing_address->getCountryId());
        $billing_address['phone']        = $order_billing_address->getTelephone();

        $order_shipping_address = $quote->getShippingAddress();
        $shipping_address = array();
        $shipping_address['first_name']   = $order_shipping_address->getFirstname();
        $shipping_address['last_name']    = $order_shipping_address->getLastname();
        $shipping_address['address']      = $order_shipping_address->getStreet()[0];
        $shipping_address['city']         = $order_shipping_address->getCity();
        $shipping_address['postal_code']  = $order_shipping_address->getPostcode();
        $shipping_address['phone']        = $order_shipping_address->getTelephone();
        $shipping_address['country_code'] =
            $this->convert_country_code($order_shipping_address->getCountryId());

        $customer_details = array();
        $customer_details['billing_address']  = $billing_address;
        $customer_details['shipping_address'] = $shipping_address;
        $customer_details['first_name']       = $order_billing_address
            ->getFirstname();
        $customer_details['last_name']        = $order_billing_address
            ->getLastname();
        $customer_details['email']            = $order_billing_address->getEmail();
        $customer_details['phone']            = $order_billing_address
            ->getTelephone();

        $customer_details['billing_address']  = $billing_address;
        $customer_details['shipping_address'] = $shipping_address;

        $items               = $quote->getAllItems();
//        var_dump($items);exit();
        $shipping_amount     = $quote->getShippingAmount();
        $shipping_tax_amount = $quote->getShippingTaxAmount();
        $tax_amount = $quote->getTaxAmount();

        $item_details = array();

        foreach ($items as $each) {
//            echo print_r($each,true);
            $item = array(
                'id'       => $each->getProductId(),
                'price'    => (string)round($each->getPrice()),
                'quantity' => (string)round($each->getQtyOrdered()),
                'name'     => $this->repString($this->getName($each->getName()))
            );

            $item_details[] = $item;
        }


        $num_products = count($item_details);

        unset($each);

        if ($quote->getDiscountAmount() != 0) {
            $couponItem = array(
                'id' => 'DISCOUNT',
                'price' => round($quote->getDiscountAmount()),
                'quantity' => 1,
                'name' => 'DISCOUNT'
            );
            $item_details[] = $couponItem;
        }

        if ($shipping_amount > 0) {
            $shipping_item = array(
                'id' => 'SHIPPING',
                'price' => round($shipping_amount),
                'quantity' => 1,
                'name' => 'Shipping Cost'
            );
            $item_details[] =$shipping_item;
        }

        if ($shipping_tax_amount > 0) {
            $shipping_tax_item = array(
                'id' => 'SHIPPING_TAX',
                'price' => round($shipping_tax_amount),
                'quantity' => 1,
                'name' => 'Shipping Tax'
            );
            $item_details[] = $shipping_tax_item;
        }

        if ($tax_amount > 0) {
            $tax_item = array(
                'id' => 'TAX',
                'price' => round($tax_amount),
                'quantity' => 1,
                'name' => 'Tax'
            );
            $item_details[] = $tax_item;
        }

        if ($quote->getBaseGiftCardsAmount() != 0) {
            $giftcardAmount = array(
                'id' => 'GIFTCARD',
                'price' => round($quote->getBaseGiftCardsAmount()*-1),
                'quantity' => 1,
                'name' => 'GIFTCARD'
            );
            $item_details[] = $giftcardAmount;
        }

        if ($quote->getBaseCustomerBalanceAmount() != 0) {
            $balancAmount = array(
                'id' => 'STORE CREDIT',
                'price' => round($quote->getBaseCustomerBalanceAmount()*-1),
                'quantity' => 1,
                'name' => 'STORE CREDIT'
            );
            $item_details[] = $balancAmount;
        }

        $totalPrice = 0;
        foreach ($item_details as $item) {
            $totalPrice += $item['price'] * $item['quantity'];
        }

        $transaction_details['gross_amount'] = $totalPrice;
        $payloads = array();
        $payloads['transaction_details'] = $transaction_details;
        $payloads['item_details']        = $item_details;
        $payloads['customer_details']    = $customer_details;


         if($oneClick == 1){    
            $credit_card['save_card'] = true;
            $payloads['user_id'] = crypt($order_billing_address->getEmail(), $serverKey);
        } 
        $credit_card['secure'] = true;
        $payloads['credit_card'] = $credit_card;
 
        if($customExpiry){
           
           $customExpiry = explode(" ", $customExpiry);
           $expiry_unit =  $customExpiry[1];
           $expiry_duration = (int)$customExpiry[0];
           error_log($expiry_unit . $expiry_duration);

           $time = time();
           $payloads['expiry'] = array(
            'start_time' => date("Y-m-d H:i:s O",$time),
            'unit' => $expiry_unit, 
            'duration'  => (int)$expiry_duration
            );

        }


        try {
//            $this->_logger->addDebug('some text or variable');
            $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/test.log');
            $logger = new \Zend\Log\Logger();
            $logger->addWriter($writer);
            error_log(print_r($payloads,true));
            $logger->info('$payloads:'.print_r($payloads,true));
//            var_dump($payloads);
//            Mage::log('$payloads:'.print_r($payloads,true),null,'snap_payloads.log',true);
            $snap = $om->get('Veritrans\Veritrans_Snap');
            $token = $snap->getSnapToken($payloads);
            $logger->info('snap token:'.print_r($token,true));
//            var_dump($redirUrl);exit();
//            error_log('snap_token:'.$token);
//            echo $token;

            $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $result->setData($token);
            return $result;



        }
        catch (Exception $e) {
            error_log($e->getMessage());
//            Mage::log('error:'.print_r($e->getMessage(),true),null,'snap.log',true);
        }

//        $page_object = $this->resultFactory->create();;
//        return $page_object;
    }

    public function setValue($order_id){
        $this->_coreSession->start();
        $this->_coreSession->setMessage($order_id);
    }

    public function setSessionData($key, $value)
    {
        return $this->_checkoutSession->setData($key, $value);
    }

    public function getSessionData($key, $remove = false)
    {
        return $this->_checkoutSession->getData($key, $remove);
    }


    public function convert_country_code( $country_code ) {

        // 3 digits country codes
        $cc_three = array(
            'AF' => 'AFG',
            'AX' => 'ALA',
            'AL' => 'ALB',
            'DZ' => 'DZA',
            'AD' => 'AND',
            'AO' => 'AGO',
            'AI' => 'AIA',
            'AQ' => 'ATA',
            'AG' => 'ATG',
            'AR' => 'ARG',
            'AM' => 'ARM',
            'AW' => 'ABW',
            'AU' => 'AUS',
            'AT' => 'AUT',
            'AZ' => 'AZE',
            'BS' => 'BHS',
            'BH' => 'BHR',
            'BD' => 'BGD',
            'BB' => 'BRB',
            'BY' => 'BLR',
            'BE' => 'BEL',
            'PW' => 'PLW',
            'BZ' => 'BLZ',
            'BJ' => 'BEN',
            'BM' => 'BMU',
            'BT' => 'BTN',
            'BO' => 'BOL',
            'BQ' => 'BES',
            'BA' => 'BIH',
            'BW' => 'BWA',
            'BV' => 'BVT',
            'BR' => 'BRA',
            'IO' => 'IOT',
            'VG' => 'VGB',
            'BN' => 'BRN',
            'BG' => 'BGR',
            'BF' => 'BFA',
            'BI' => 'BDI',
            'KH' => 'KHM',
            'CM' => 'CMR',
            'CA' => 'CAN',
            'CV' => 'CPV',
            'KY' => 'CYM',
            'CF' => 'CAF',
            'TD' => 'TCD',
            'CL' => 'CHL',
            'CN' => 'CHN',
            'CX' => 'CXR',
            'CC' => 'CCK',
            'CO' => 'COL',
            'KM' => 'COM',
            'CG' => 'COG',
            'CD' => 'COD',
            'CK' => 'COK',
            'CR' => 'CRI',
            'HR' => 'HRV',
            'CU' => 'CUB',
            'CW' => 'CUW',
            'CY' => 'CYP',
            'CZ' => 'CZE',
            'DK' => 'DNK',
            'DJ' => 'DJI',
            'DM' => 'DMA',
            'DO' => 'DOM',
            'EC' => 'ECU',
            'EG' => 'EGY',
            'SV' => 'SLV',
            'GQ' => 'GNQ',
            'ER' => 'ERI',
            'EE' => 'EST',
            'ET' => 'ETH',
            'FK' => 'FLK',
            'FO' => 'FRO',
            'FJ' => 'FJI',
            'FI' => 'FIN',
            'FR' => 'FRA',
            'GF' => 'GUF',
            'PF' => 'PYF',
            'TF' => 'ATF',
            'GA' => 'GAB',
            'GM' => 'GMB',
            'GE' => 'GEO',
            'DE' => 'DEU',
            'GH' => 'GHA',
            'GI' => 'GIB',
            'GR' => 'GRC',
            'GL' => 'GRL',
            'GD' => 'GRD',
            'GP' => 'GLP',
            'GT' => 'GTM',
            'GG' => 'GGY',
            'GN' => 'GIN',
            'GW' => 'GNB',
            'GY' => 'GUY',
            'HT' => 'HTI',
            'HM' => 'HMD',
            'HN' => 'HND',
            'HK' => 'HKG',
            'HU' => 'HUN',
            'IS' => 'ISL',
            'IN' => 'IND',
            'ID' => 'IDN',
            'IR' => 'RIN',
            'IQ' => 'IRQ',
            'IE' => 'IRL',
            'IM' => 'IMN',
            'IL' => 'ISR',
            'IT' => 'ITA',
            'CI' => 'CIV',
            'JM' => 'JAM',
            'JP' => 'JPN',
            'JE' => 'JEY',
            'JO' => 'JOR',
            'KZ' => 'KAZ',
            'KE' => 'KEN',
            'KI' => 'KIR',
            'KW' => 'KWT',
            'KG' => 'KGZ',
            'LA' => 'LAO',
            'LV' => 'LVA',
            'LB' => 'LBN',
            'LS' => 'LSO',
            'LR' => 'LBR',
            'LY' => 'LBY',
            'LI' => 'LIE',
            'LT' => 'LTU',
            'LU' => 'LUX',
            'MO' => 'MAC',
            'MK' => 'MKD',
            'MG' => 'MDG',
            'MW' => 'MWI',
            'MY' => 'MYS',
            'MV' => 'MDV',
            'ML' => 'MLI',
            'MT' => 'MLT',
            'MH' => 'MHL',
            'MQ' => 'MTQ',
            'MR' => 'MRT',
            'MU' => 'MUS',
            'YT' => 'MYT',
            'MX' => 'MEX',
            'FM' => 'FSM',
            'MD' => 'MDA',
            'MC' => 'MCO',
            'MN' => 'MNG',
            'ME' => 'MNE',
            'MS' => 'MSR',
            'MA' => 'MAR',
            'MZ' => 'MOZ',
            'MM' => 'MMR',
            'NA' => 'NAM',
            'NR' => 'NRU',
            'NP' => 'NPL',
            'NL' => 'NLD',
            'AN' => 'ANT',
            'NC' => 'NCL',
            'NZ' => 'NZL',
            'NI' => 'NIC',
            'NE' => 'NER',
            'NG' => 'NGA',
            'NU' => 'NIU',
            'NF' => 'NFK',
            'KP' => 'MNP',
            'NO' => 'NOR',
            'OM' => 'OMN',
            'PK' => 'PAK',
            'PS' => 'PSE',
            'PA' => 'PAN',
            'PG' => 'PNG',
            'PY' => 'PRY',
            'PE' => 'PER',
            'PH' => 'PHL',
            'PN' => 'PCN',
            'PL' => 'POL',
            'PT' => 'PRT',
            'QA' => 'QAT',
            'RE' => 'REU',
            'RO' => 'SHN',
            'RU' => 'RUS',
            'RW' => 'EWA',
            'BL' => 'BLM',
            'SH' => 'SHN',
            'KN' => 'KNA',
            'LC' => 'LCA',
            'MF' => 'MAF',
            'SX' => 'SXM',
            'PM' => 'SPM',
            'VC' => 'VCT',
            'SM' => 'SMR',
            'ST' => 'STP',
            'SA' => 'SAU',
            'SN' => 'SEN',
            'RS' => 'SRB',
            'SC' => 'SYC',
            'SL' => 'SLE',
            'SG' => 'SGP',
            'SK' => 'SVK',
            'SI' => 'SVN',
            'SB' => 'SLB',
            'SO' => 'SOM',
            'ZA' => 'ZAF',
            'GS' => 'SGS',
            'KR' => 'KOR',
            'SS' => 'SSD',
            'ES' => 'ESP',
            'LK' => 'LKA',
            'SD' => 'SDN',
            'SR' => 'SUR',
            'SJ' => 'SJM',
            'SZ' => 'SWZ',
            'SE' => 'SWE',
            'CH' => 'CHE',
            'SY' => 'SYR',
            'TW' => 'TWN',
            'TJ' => 'TJK',
            'TZ' => 'TZA',
            'TH' => 'THA',
            'TL' => 'TLS',
            'TG' => 'TGO',
            'TK' => 'TKL',
            'TO' => 'TON',
            'TT' => 'TTO',
            'TN' => 'TUN',
            'TR' => 'TUR',
            'TM' => 'TKM',
            'TC' => 'TCA',
            'TV' => 'TUV',
            'UG' => 'UGA',
            'UA' => 'UKR',
            'AE' => 'ARE',
            'GB' => 'GBR',
            'US' => 'USA',
            'UY' => 'URY',
            'UZ' => 'UZB',
            'VU' => 'VUT',
            'VA' => 'VAT',
            'VE' => 'VEN',
            'VN' => 'VNM',
            'WF' => 'WLF',
            'EH' => 'ESH',
            'WS' => 'WSM',
            'YE' => 'YEM',
            'ZM' => 'ZMB',
            'ZW' => 'ZWE'
        );

        // Check if country code exists
        if( isset( $cc_three[ $country_code ] ) && $cc_three[ $country_code ] != '' ) {
            $country_code = $cc_three[ $country_code ];
        }

        return $country_code;
    }

    private function repString($str){
        return preg_replace("/[^a-zA-Z0-9]+/", " ", $str);
    }

    private function getName($s)
    {
        $max_length = 20;
        if (strlen($s) > $max_length) {
            $offset = ($max_length - 3) - strlen($s);
            $s      = substr($s, 0, strrpos($s, ' ', $offset));
        }
        return $s;
    }
}
