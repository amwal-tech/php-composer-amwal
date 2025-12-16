<?php
require_once 'vendor/autoload.php';

use Amwal\Payment\AmwalPay;
// Option 1: Simple with environment detection
$amwal = new AmwalPay([
    'amwalPublicKey' => 'sandbox-amwal-32b58269-5b24-43bb-9ca5-b57737394d56',
    'amwalSecretAPIKey' => 'a7ab077d-db4c-408b-a199-9c713536ada5',
]);

$paymentData=[
    'amount'=>100, // minimum requirement 
    // 'language'=>'en',
    // 'description'=>'Test Payment',
    // 'client_email'=>'test@example.com',
    // 'callback_url'=>'https://example.com/callback',
    // 'client_phone_number'=>'+966501234567',
];
$storeId='8ff41fae-709a-46da-9b27-a5d2b1059e67';

try {
    // // validate merchant configuration
    $amwal->testConnection();

    // create payment
    $payment=$amwal->createPayment($paymentData, $storeId);
    echo 'Amwal Payment URL <a href="'.$payment['payment_url'].'">Click Here</a>'; 

    // Getting Payment Link ID details
    $paymentDetails=$amwal->getPaymentDetails($payment['payment_link_id']);
    echo 'Payment Details: <pre>'; print_r($paymentDetails);

    // Getting Transaction ID details
    $transactionDetails=$amwal->getPaymentDetails('0f0a7c6a-ca74-4fae-8d9c-290b722b6750',false);
    echo 'Specific Transaction Details: <pre>'; print_r($transactionDetails);

    // refund an amount for specific transaction
    $refundData = ['refund_amount'=>10,
    'transaction_id'=>'0f0a7c6a-ca74-4fae-8d9c-290b722b6750',
    ];
    $refundDetails=$amwal->refundPayment($refundData);
    echo 'Refund Details: <pre>'; print_r($refundDetails);
} catch (\Amwal\Payment\Exceptions\AmwalPayException $e) {
    echo "Error: " . $e->getMessage();
}
