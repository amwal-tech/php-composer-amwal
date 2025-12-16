<?php

declare(strict_types=1);

namespace Amwal\Payment;

use Amwal\Payment\Exceptions\AmwalPayException;
use Amwal\Payment\Exceptions\AmwalPayValidationException;
use Amwal\Payment\Exceptions\AmwalPayNetworkException;

class AmwalPay {
    // Constants
    private const CONFIG_SECRET_KEY = 'amwalSecretAPIKey';
    private const CONFIG_PUBLIC_KEY = 'amwalPublicKey';
    private const CONFIG_API_URL = 'amwalApiURL';
    private const CONFIG_LOG = 'log';
    private const CONFIG_TIMEOUT = 'timeout';
    private const CONFIG_MAX_RETRIES = 'maxRetries';
    private const CONFIG_ENVIRONMENT = 'environment';
    private const CONFIG_DEFAULT_ORIGIN = 'defaultOrigin';
    
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_LOG_FILE = '../logs/amwalpay.log';
    private const DEFAULT_ENVIRONMENT = 'production';
    
    private const API_ENDPOINTS = [
        'production' => 'https://backend.sa.amwal.tech'
    ];

    private array $config = [];
    private static array $rateLimit = [];

    public function __construct(array $config) {
        $this->validateConfig($config);
        $this->initializeConfig($config);
    }

    private function validateConfig(array $config): void {
        $required = [self::CONFIG_SECRET_KEY, self::CONFIG_PUBLIC_KEY];
        
        foreach ($required as $key) {
            if (empty($config[$key] ?? null)) {
                throw new AmwalPayValidationException("Configuration parameter '{$key}' is required");
            }
        }

        if (isset($config[self::CONFIG_API_URL], $config[self::CONFIG_API_URL][0]) && 
            !filter_var($config[self::CONFIG_API_URL], FILTER_VALIDATE_URL)) {
            throw new AmwalPayValidationException("Invalid API URL: {$config[self::CONFIG_API_URL]}");
        }

        if (isset($config[self::CONFIG_LOG]) && !$this->isWritablePath($config[self::CONFIG_LOG])) {
            throw new AmwalPayValidationException("Log file not writable: {$config[self::CONFIG_LOG]}");
        }
    }

    private function initializeConfig(array $config): void {
        $environment = $config[self::CONFIG_ENVIRONMENT] ?? self::DEFAULT_ENVIRONMENT;
        $apiUrl = $config[self::CONFIG_API_URL] ?? self::API_ENDPOINTS[$environment] ?? self::API_ENDPOINTS['production'];
        
        $this->config = [
            self::CONFIG_SECRET_KEY => trim((string)$config[self::CONFIG_SECRET_KEY]),
            self::CONFIG_PUBLIC_KEY => trim((string)$config[self::CONFIG_PUBLIC_KEY]),
            self::CONFIG_API_URL => rtrim($apiUrl, '/'),
            self::CONFIG_LOG => $config[self::CONFIG_LOG] ?? self::DEFAULT_LOG_FILE,
            self::CONFIG_TIMEOUT => $config[self::CONFIG_TIMEOUT] ?? self::DEFAULT_TIMEOUT,
            self::CONFIG_MAX_RETRIES => $config[self::CONFIG_MAX_RETRIES] ?? self::DEFAULT_MAX_RETRIES,
            self::CONFIG_ENVIRONMENT => $environment,
            self::CONFIG_DEFAULT_ORIGIN => $config[self::CONFIG_DEFAULT_ORIGIN] ?? null,
        ];
    }

    public function validateMerchant(): array {
        $url = $this->config[self::CONFIG_API_URL] . '/api/validate-merchant-api-key/';
        $headers = $this->getDefaultHeaders($this->config[self::CONFIG_PUBLIC_KEY]);
        $payload = ['api_key' => $this->config[self::CONFIG_SECRET_KEY], 'merchant_id' => $this->config[self::CONFIG_PUBLIC_KEY]];

        try {
            $response = $this->makeHttpRequest($url, $headers, $payload, 'POST');
            $this->log('info', 'Merchant validation successful', [
                'merchant_id' => $this->config[self::CONFIG_SECRET_KEY],
                'environment' => $this->config[self::CONFIG_ENVIRONMENT],
            ]);
            return $response;
        } catch (AmwalPayException $e) {
            $this->log('error', 'Merchant validation failed', [
                'error' => $e->getMessage(),
                'environment' => $this->config[self::CONFIG_ENVIRONMENT],
            ]);
            throw $e;
        }
    }

    public function createPayment(array $paymentData, string $storeId, ?string $origin = null): array {
        $this->validatePaymentData($paymentData);
        $effectiveOrigin = $origin ?? $this->config[self::CONFIG_DEFAULT_ORIGIN];
        $url = $this->config[self::CONFIG_API_URL] . "/payment_links/{$storeId}/create";
        $headers = $this->getAuthHeaders($effectiveOrigin);

        $this->log('info', 'Creating payment', [
            'merchant_id' => $this->config[self::CONFIG_SECRET_KEY],
            'amount' => $paymentData['amount'] ?? null,
            'environment' => $this->config[self::CONFIG_ENVIRONMENT],
        ]);

        $response = $this->makeHttpRequest($url, $headers, $paymentData, 'POST');

        if (!isset($response['url'])) {
            $this->log('error', 'Invalid response from payment creation', ['response' => $response]);
            throw new AmwalPayException('Invalid response from payment gateway');
        }

        $this->log('info', 'Payment created successfully', [
            'payment_link_id' => $response['payment_link_id'] ?? null,
        ]);

        return [
            'environment' => $response['environment'] ?? null,
            'payment_url' => $response['url'],
            'payment_link_id' => $response['payment_link_id'] ?? null,
        ];
    }

    public function getPaymentDetails(string $transactionId, bool $isPaymentDetails = true): array {
        $this->validateTransactionId($transactionId);
        $endpoint = $isPaymentDetails ? 'payment_links' : 'transactions';
        $suffix = $isPaymentDetails ? '/details' : '';
        $url = $this->config[self::CONFIG_API_URL] . "/{$endpoint}/{$transactionId}{$suffix}";
        return $this->makeHttpRequest($url, $this->getAuthHeaders(), [], 'GET');
    }

    public function refundPayment(array $refundData): array {
        if (empty($refundData['transaction_id'])) {
            throw new AmwalPayValidationException('Transaction ID is required for refund');
        }

        $transactionId = $refundData['transaction_id'];
        $url = $this->config[self::CONFIG_API_URL] . "/transactions/refund/{$transactionId}/";

        $this->log('info', 'Processing refund', [
            'transaction_id' => $transactionId,
            'environment' => $this->config[self::CONFIG_ENVIRONMENT],
        ]);

        return $this->makeHttpRequest($url, $this->getAuthHeaders(), $refundData, 'POST');
    }

    private function makeHttpRequest(string $url, array $headers, array $payload = [], string $method = 'POST', int $retryCount = 0): array {
        $this->checkRateLimit($url);
        
        if (!extension_loaded('curl')) {
            throw new AmwalPayException('cURL extension is required');
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => $this->config[self::CONFIG_TIMEOUT],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => !in_array($this->config[self::CONFIG_ENVIRONMENT], ['development', 'local', 'test']),
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method !== 'GET' && !empty($payload)) {
            $jsonPayload = json_encode($payload);
            $options[CURLOPT_POSTFIELDS] = $jsonPayload;
            $this->log('debug', 'Request payload', ['payload_size' => strlen($jsonPayload)]);
        }

        curl_setopt_array($ch, $options);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $duration = microtime(true) - $startTime;
        curl_close($ch);

        $this->log('debug', 'HTTP Request', [
            'url' => $url,
            'method' => $method,
            'status' => $httpCode,
            'duration' => round($duration, 3),
            'environment' => $this->config[self::CONFIG_ENVIRONMENT],
        ]);

        if ($response === false) {
            $this->log('error', 'cURL request failed', ['error' => $error, 'retry_count' => $retryCount]);
            
            if ($retryCount < $this->config[self::CONFIG_MAX_RETRIES]) {
                usleep(100000 * (2 ** $retryCount)); // Exponential backoff
                return $this->makeHttpRequest($url, $headers, $payload, $method, $retryCount + 1);
            }

            throw new AmwalPayNetworkException("HTTP request failed: {$error}", 0, [
                'url' => $url,
                'error' => $error,
                'environment' => $this->config[self::CONFIG_ENVIRONMENT],
            ]);
        }

        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AmwalPayException('Invalid JSON response', 0, [
                'json_error' => json_last_error_msg(),
                'environment' => $this->config[self::CONFIG_ENVIRONMENT],
            ]);
        }

        if ($httpCode >= 400) {
            $errorMessage = $decodedResponse['message'] ?? $decodedResponse['error'] ?? 'Unknown error';
            throw new AmwalPayException("API Error ({$httpCode}): {$errorMessage}", $httpCode, [
                'response' => $decodedResponse,
                'environment' => $this->config[self::CONFIG_ENVIRONMENT],
            ]);
        }

        return $decodedResponse;
    }

    private function getDefaultHeaders(?string $origin = null): array {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($origin) {
            $headers[] = "Origin: {$origin}";
        }
        return $headers;
    }

    private function getAuthHeaders(?string $origin = null): array {
        return array_merge($this->getDefaultHeaders($origin), [
            'X-Amwal-Key: ' . $this->config[self::CONFIG_PUBLIC_KEY],
            'Authorization: ' . $this->config[self::CONFIG_SECRET_KEY],
        ]);
    }

    private function validatePaymentData(array $paymentData): void {
        if (!isset($paymentData['amount']) || $paymentData['amount'] === '' || $paymentData['amount'] === null) {
            throw new AmwalPayValidationException("Required field 'amount' is missing");
        }

        if (!is_numeric($paymentData['amount']) || $paymentData['amount'] <= 0) {
            throw new AmwalPayValidationException('Amount must be a positive number');
        }

        if (isset($paymentData['client_phone_number'])) {
            $paymentData['client_phone_number'] = self::validatePhone($paymentData['client_phone_number']);
        }

        if (isset($paymentData['callback_url']) && !filter_var($paymentData['callback_url'], FILTER_VALIDATE_URL)) {
            throw new AmwalPayValidationException('Invalid callback URL');
        }
    }

    private function validateTransactionId(string $transactionId): void {
        if (!preg_match('/^[a-zA-Z0-9_-]{10,100}$/', $transactionId)) {
            throw new AmwalPayValidationException('Invalid transaction ID format');
        }
    }

    public static function validatePhone(string $phone): string {
        $normalized = self::normalizePhoneNumber($phone);
        if (!self::isValidPhoneNumber($normalized)) {
            throw new AmwalPayValidationException('Invalid phone number format');
        }
        return $normalized;
    }

    private static function normalizePhoneNumber(string $phone): string {
        $phone = self::convertToEnglishNumerals($phone);
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return strpos($phone, '+') !== 0 ? ltrim($phone, '0') : $phone;
    }

    private function isWritablePath(string $path): bool {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return @mkdir($dir, 0755, true) && is_writable($dir);
        }
        return is_writable($dir);
    }

    private function checkRateLimit(string $endpoint): void {
        if (in_array($this->config[self::CONFIG_ENVIRONMENT], ['development', 'local', 'test'])) {
            return;
        }

        $key = md5($endpoint . $this->config[self::CONFIG_SECRET_KEY]);
        $now = time();
        
        if (isset(self::$rateLimit[$key]) && ($now - self::$rateLimit[$key]) < 60) {
            throw new AmwalPayException('Rate limit exceeded');
        }
        
        self::$rateLimit[$key] = $now;
    }

    private function log(string $level, string $message, array $context = []): void {
        if (empty($this->config[self::CONFIG_LOG])) return;

        $context['env'] = $this->config[self::CONFIG_ENVIRONMENT];
        $logEntry = sprintf("[%s] %s: %s %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            json_encode($context, JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );

        @file_put_contents($this->config[self::CONFIG_LOG], $logEntry, FILE_APPEND | LOCK_EX);
    }

    private static function convertToEnglishNumerals(string $number): string {
        $patterns = [
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        ];

        foreach ($patterns as $pattern) {
            $number = str_replace($pattern, range(0, 9), $number);
        }

        return $number;
    }

    public static function getCountryPhoneCodes(): array {
        return [
            'AF' => '93', 'AL' => '355', 'DZ' => '213', 'AS' => '1684', 'AD' => '376', 'AO' => '244',
            'AI' => '1264', 'AQ' => '672', 'AG' => '1268', 'AR' => '54', 'AM' => '374', 'AW' => '297',
            'AU' => '61', 'AT' => '43', 'AZ' => '994', 'BS' => '1242', 'BH' => '973', 'BD' => '880',
            'BB' => '1246', 'BY' => '375', 'BE' => '32', 'BZ' => '501', 'BJ' => '229', 'BM' => '1441',
            'BT' => '975', 'BO' => '591', 'BA' => '387', 'BW' => '267', 'BR' => '55', 'IO' => '246',
            'BN' => '673', 'BG' => '359', 'BF' => '226', 'BI' => '257', 'KH' => '855', 'CM' => '237',
            'CA' => '1', 'CV' => '238', 'KY' => '1345', 'CF' => '236', 'TD' => '235', 'CL' => '56',
            'CN' => '86', 'CO' => '57', 'KM' => '269', 'CG' => '242', 'CD' => '243', 'CR' => '506',
            'HR' => '385', 'CU' => '53', 'CY' => '357', 'CZ' => '420', 'DK' => '45', 'DJ' => '253',
            'DM' => '1767', 'DO' => '1', 'EC' => '593', 'EG' => '20', 'SV' => '503', 'GQ' => '240',
            'ER' => '291', 'EE' => '372', 'ET' => '251', 'FJ' => '679', 'FI' => '358', 'FR' => '33',
            'GA' => '241', 'GM' => '220', 'GE' => '995', 'DE' => '49', 'GH' => '233', 'GR' => '30',
            'GD' => '1473', 'GT' => '502', 'GN' => '224', 'GW' => '245', 'GY' => '592', 'HT' => '509',
            'HN' => '504', 'HU' => '36', 'IS' => '354', 'IN' => '91', 'ID' => '62', 'IR' => '98',
            'IQ' => '964', 'IE' => '353', 'IL' => '972', 'IT' => '39', 'JM' => '1876', 'JP' => '81',
            'JO' => '962', 'KZ' => '7', 'KE' => '254', 'KI' => '686', 'KP' => '850', 'KR' => '82',
            'KW' => '965', 'KG' => '996', 'LA' => '856', 'LV' => '371', 'LB' => '961', 'LS' => '266',
            'LR' => '231', 'LY' => '218', 'LI' => '423', 'LT' => '370', 'LU' => '352', 'MG' => '261',
            'MW' => '265', 'MY' => '60', 'MV' => '960', 'ML' => '223', 'MT' => '356', 'MH' => '692',
            'MR' => '222', 'MU' => '230', 'MX' => '52', 'FM' => '691', 'MD' => '373', 'MC' => '377',
            'MN' => '976', 'ME' => '382', 'MA' => '212', 'MZ' => '258', 'MM' => '95', 'NA' => '264',
            'NR' => '674', 'NP' => '977', 'NL' => '31', 'NZ' => '64', 'NI' => '505', 'NE' => '227',
            'NG' => '234', 'NO' => '47', 'OM' => '968', 'PK' => '92', 'PW' => '680', 'PA' => '507',
            'PG' => '675', 'PY' => '595', 'PE' => '51', 'PH' => '63', 'PL' => '48', 'PT' => '351',
            'QA' => '974', 'RO' => '40', 'RU' => '7', 'RW' => '250', 'KN' => '1869', 'LC' => '1758',
            'VC' => '1784', 'WS' => '685', 'SM' => '378', 'ST' => '239', 'SA' => '966', 'SN' => '221',
            'RS' => '381', 'SC' => '248', 'SL' => '232', 'SG' => '65', 'SK' => '421', 'SI' => '386',
            'SB' => '677', 'SO' => '252', 'ZA' => '27', 'SS' => '211', 'ES' => '34', 'LK' => '94',
            'SD' => '249', 'SR' => '597', 'SE' => '46', 'CH' => '41', 'SY' => '963', 'TW' => '886',
            'TJ' => '992', 'TZ' => '255', 'TH' => '66', 'TL' => '670', 'TG' => '228', 'TO' => '676',
            'TT' => '1868', 'TN' => '216', 'TR' => '90', 'TM' => '993', 'UG' => '256', 'UA' => '380',
            'AE' => '971', 'GB' => '44', 'US' => '1', 'UY' => '598', 'UZ' => '998', 'VU' => '678',
            'VA' => '379', 'VE' => '58', 'VN' => '84', 'YE' => '967', 'ZM' => '260', 'ZW' => '263',
        ];
    }

    public static function getPhoneCodeFromISO(string $isoCode): ?string {
        return self::getCountryPhoneCodes()[strtoupper($isoCode)] ?? null;
    }

    public static function hasCountryCodePrefix(string $phone): bool {
        $phone = ltrim($phone, '+');
        foreach (self::getCountryPhoneCodes() as $code) {
            if (strpos($phone, $code) === 0) return true;
        }
        return false;
    }

    public static function isValidPhoneNumber(string $number): bool {
        return (bool)preg_match('/^\+[1-9]\d{1,14}$/', $number);
    }

    public static function filterInput(string $key, string $type = 'GET', int $filter = FILTER_DEFAULT, $default = null) {
        $sources = ['GET' => INPUT_GET, 'POST' => INPUT_POST, 'COOKIE' => INPUT_COOKIE];
        $inputType = $sources[strtoupper($type)] ?? null;
        return $inputType ? filter_input($inputType, $key, $filter) ?? $default : $default;
    }

    public function getConfig(string $key) {
        return $this->config[$key] ?? null;
    }

    public function setConfig(string $key, $value): void {
        $immutable = [self::CONFIG_SECRET_KEY, self::CONFIG_PUBLIC_KEY, self::CONFIG_API_URL];
        if (in_array($key, $immutable)) {
            throw new AmwalPayValidationException("Cannot modify '{$key}' after initialization");
        }
        $this->config[$key] = $value;
    }

    public function getEnvironment(): string {
        return $this->config[self::CONFIG_ENVIRONMENT];
    }

    public function getApiUrl(): string {
        return $this->config[self::CONFIG_API_URL];
    }

    public static function create(string $secretKey, string $publicKey, string $environment = 'production', array $additionalConfig = []): self {
        $config = array_merge($additionalConfig, [
            self::CONFIG_SECRET_KEY => $secretKey,
            self::CONFIG_PUBLIC_KEY => $publicKey,
            self::CONFIG_ENVIRONMENT => $environment,
        ]);
        return new self($config);
    }

    public function testConnection(): bool {
        try {
            $this->validateMerchant();
            return true;
            } catch (AmwalPayException $e) {
            return false;
        }
    }
}
