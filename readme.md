# PHP Composer Package for Amwal Payment Flow

A modern, intuitive PHP composer packages for seamless integration with the Amwal Tech. Create payments, handle callbacks, check statuses, and process refunds with minimal code.

## Features
- **Simple Configuration**: Get started in under 5 minutes
- **Full Payment Flow**: Create, retrieve, and refund payments
- **Sandbox & Production**: Built-in environment detection
- **Exception Handling**: Comprehensive error handling with detailed messages
- **PSR Compliant**: Follows PHP standards for easy integration

## Installation

### Via Composer
```bash
composer require amwal/php-sdk-composer
```

### Configuration
```PHP 
$amwal = new AmwalPay([
    'amwalPublicKey' => 'sandbox-XXXX', // or 'production-yyy'
    'amwalSecretAPIKey' => 'SECRET API Key',
]);

```
### Validate Keys
```PHP
// validate merchant configuration
    $amwal->testConnection();
```
### Create Payment 
```PHP
// Amwal Store ID
$storeId='Amwal-Store-ID';

// Payment Object
$paymentData=[
    'amount'=>100, // minimum requirement 
    // 'currency'=>'SAR',
    // 'description'=>'Test Payment',
    // 'customer_email'=>'test@example.com',
    // 'callbackUrl'=>'https://example.com/callback',
    // 'client_phone_number'=>'+966501234567',
];

$payment=$amwal->createPayment($paymentData, $storeId);
echo 'Amwal Payment URL <a href="'.$payment['payment_url'].'">Click Here</a> <br/>'; 
echo 'Amwal Payment Link ID '.$payment['payment_link_id'];
```
### Get Payment / Transaction Details
```PHP
    // Getting Payment Link ID details
    $paymentDetails=$amwal->getPaymentDetails('amwal-Payment-Link-ID');
    echo 'Payment Details: <pre>'; print_r($paymentDetails);

    // Getting Transaction ID details
    $transactionDetails=$amwal->getPaymentDetails('amwal-trx-ID',false);
    echo 'Specific Transaction Details: <pre>'; print_r($transactionDetails);
```

### Refund / Partial refund amount for specific transaction
```PHP 
    // refund an amount for specific transaction
    $refundData = ['refund_amount'=>10,
    'transaction_id'=>'amwal-trx-ID',
    ];
    $refundDetails=$amwal->refundPayment($refundData);
    echo 'Refund Details: <pre>'; print_r($refundDetails);
```

