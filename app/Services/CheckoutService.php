<?php

namespace App\Services;

use App\Enums\OrderStatusEnum;
use App\Exceptions\PaymentException;
use App\Models\Order;
use Database\Seeders\OrderSeeder;
use Illuminate\Support\Str;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Payment;

class CheckoutService
{

    public function __construct()
    {
        MercadoPagoConfig::setAccessToken(config('payment.mercadopago.access_token'));
    }

    public function loadCart(): array
    {
        $cart = Order::with('skus.product', 'skus.features')
            ->where('status', OrderStatusEnum::CART)
            ->where(function ($query) {
                $query->where('session_id', session()->getId());
                if (auth()->check()) {
                    $query->orWhere('user_id', auth()->user()->id);
                }
            })->first();

        if (!$cart && config('app.env') == 'local' || config('app.env') == 'testing') {
            $seed = new OrderSeeder();
            $seed->run(session()->getId());
            return $this->loadCart();
        }

        return $cart->toArray();
    }

    public function creditCardPayment($data, $user, $address)
    {
        try {

        $client = new PaymentClient(); 

            $idempotencyKey = Str::uuid()->toString();
            $request_options = new RequestOptions();
            $request_options->setCustomHeaders(["X-Idempotency-Key: $idempotencyKey"]);


            $payment = $client->create([
                "transaction_amount" =>  (float)$data['transaction_amount'],
                "token" => $data['token'],
                "description" => $data['description'],
                "installments" => (int)$data['installments'],
                "payment_method_id" => $data['payment_method_id'],
                "payer" => [
                 "email" => $user['email'],
                    "identification" => [
                        "type" => "cpf",
                        "number" => $user['cpf']
                    ],
                ]
        ], $request_options);


        return $payment;
    } catch (MPApiException $e) {
        throw new PaymentException("Erro ao conectar com o MercadoPago: " . $e->getMessage());
    }
    }

    public function pixOrBankSlipPayment($data, $user, $address)
    {

        try {
            $client = new PaymentClient(); 

            $idempotencyKey = Str::uuid()->toString();
            $request_options = new RequestOptions();
            $request_options->setCustomHeaders(["X-Idempotency-Key: $idempotencyKey"]);


          $payment = $client->create([
                "transaction_amount" => (float)$data['amount'],
                "description" => "Título do produto",
                "payment_method_id" => $data['method'],
                "payer" => [
                    "email" => $user['email'],
                    "identification" => [
                        "type" => "cpf",
                        "number" => $user['cpf']
                    ],
                    "address" => [
                        "zip_code" => $address['zipcode'],
                        "city" =>  $address['city'],
                        "street_name" => $address['address'],
                        "street_number" =>  $address['number'],
                        "neighborhood" =>$address['district'],
                        "federal_unit" => $address['state']
                      ]
                ]
            ], $request_options);


            return $payment;
        } catch (MPApiException $e) {
            throw new PaymentException("Erro ao conectar com o MercadoPago: " . $e->getMessage());
        }
    }
    public function buildPayer($user, $address)
    {
        $first_name = explode(' ', $user['name'])[0];
        return array(
            "email" => $user['email'],
            "first_name" => $first_name,
            "last_name" => Str::of($user['name'])->after($first_name)->trim(),
            "identification" => array(
                "type" => "CPF",
                "number" => $user['cpf']
            ),
            "address"=>  array(
                "zip_code" => $address['zipcode'],
                "street_name" => $address['address'],
                "street_number" => $address['number'],
                "neighborhood" => $address['district'],
                "city" => $address['city'],
                "federal_unit" => $address['state']
            )
        );
    }

}