<?php
declare(strict_types=1);

namespace MagentoDev\ImportRatesCentralBankCz\Model;

use Exception;
use Laminas\Http\Request;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\LaminasClientFactory as HttpClientFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\HTTP\LaminasClient;

/**
 * Currency rate import model (From http://fixer.io/)
 */
class Import extends AbstractImport
{
    /**
     * @var string
     */
    public const CURRENCY_CONVERTER_URL = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';

    /**
     * @var string
     */
    private const CURRENCY_DEFAULT_CONVERT = 'CZK';

    /**
     * @var HttpClientFactory
     */
    protected $httpClientFactory;

    /**
     * Core scope config
     *
     * @var ScopeConfigInterface
     */
    private $scopeConfig;


    /**
     * Initialize dependencies
     *
     * @param CurrencyFactory $currencyFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param HttpClientFactory $httpClientFactory
     */
    public function __construct(
        CurrencyFactory $currencyFactory,
        ScopeConfigInterface $scopeConfig,
        HttpClientFactory $httpClientFactory
    ) {
        parent::__construct($currencyFactory);
        $this->scopeConfig = $scopeConfig;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * @inheritdoc
     */
    public function fetchRates()
    {
        $data = [];
        $currencies = $this->_getCurrencyCodes();
        $defaultCurrencies = $this->_getDefaultCurrencyCodes();

        if(!$defaultCurrencies){
            $this->_messages[] = __('Default currency not set');
            return $data;
        }
        foreach ($defaultCurrencies as $currencyFrom) {
            if($currencyFrom != self::CURRENCY_DEFAULT_CONVERT){
                $this->_messages[] = __('Default currency should be %1', self::CURRENCY_DEFAULT_CONVERT);
                return $data;
            }
        }
        $convertData = $this->getConvertData();
        if(!$convertData){
            $this->_messages[] = __('Convert data from the CZ bank has not been received');
            return $data;
        }

        foreach ($defaultCurrencies as $currencyFrom) {
            if (!isset($data[$currencyFrom])) {
                $data[$currencyFrom] = [];
            }
            $data = $this->convertBatch($data, $currencyFrom, $currencies, $convertData);
            ksort($data[$currencyFrom]);
        }
        return $data;
    }

    /**
     * Get convert data from CZ Bank
     * @return array
     */

    private function getConvertData(): array
    {
        $response = '';
        $convertData = [];
        $url = self::CURRENCY_CONVERTER_URL;
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        set_time_limit(0);
        try {
            $response = $this->getServiceResponse($url);
        } finally {
            ini_restore('max_execution_time');
        }
        if ($this->validateResponse($response)) {
            $response = explode("\n",$response);
            if($response){
                foreach ($response as $key=>$item) {
                    if($key<2) continue;
                    $currency_info = explode("|",$item);
                    if($currency_info){
                        $amount = isset($currency_info[2]) ? $this->_numberFormat($currency_info[2]) : '';
                        $code = $currency_info[3] ?? '';
                        $rate = isset($currency_info[4]) ? $this->_numberFormat($currency_info[4]) : '';
                        if($amount > 1 && $rate){
                            $rate = $rate/$amount;
                        }
                        if($code && $rate){
                            $convertData[$code] = $rate;
                        }
                    }
                }
            }
        }

        return $convertData;
    }
    /**
     * @inheritdoc
     */
    protected function _convert($currencyFrom, $currencyTo)
    {
        return 1;
    }
    /**
     * Return currencies convert rates in batch mode
     *
     * @param array $data
     * @param string $currencyFrom
     * @param array $currenciesTo
     * @param array $convertData
     * @return array
     */
    private function convertBatch(array $data, string $currencyFrom, array $currenciesTo, array $convertData): array
    {
        foreach ($currenciesTo as $currencyTo) {
            if ($currencyFrom == $currencyTo) {
                $data[$currencyFrom][$currencyTo] = $this->_numberFormat(1);
            } else {
                if (empty($convertData[$currencyTo])) {
                    $this->_messages[] = __('We can\'t retrieve a rate from %1 for %2.', $currencyFrom, $currencyTo);
                    $data[$currencyFrom][$currencyTo] = null;
                } else {
                    $data[$currencyFrom][$currencyTo] = $this->_numberFormat(
                        (double)$convertData[$currencyTo]
                    );
                }
            }
        }
        return $data;
    }

    /**
     * Get Fixer.io service response
     *
     * @param string $url
     * @param int $retry
     * @return string
     */
    private function getServiceResponse(string $url, int $retry = 0): string
    {
        /** @var LaminasClient $httpClient */
        $httpClient = $this->httpClientFactory->create();
        $response = '';

        try {
            $httpClient->setUri($url);
            $httpClient->setOptions(
                [
                    'timeout' => 100,
                ]
            );
            $httpClient->setMethod(Request::METHOD_GET);
            $response = $httpClient->send()->getBody();
        } catch (Exception $e) {
            if ($retry == 0) {
                $response = $this->getServiceResponse($url, 1);
            }
        }
        return $response;
    }

    /**
     * Validates rates response.
     *
     * @param array $response
     * @param string $baseCurrency
     * @return bool
     */
    private function validateResponse(string $response): bool
    {
        if ($response) {
            return true;
        }
        return false;
    }


}
