<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('EDD_sadad_Gateway')):

    class EDD_sadad_Gateway
    {
        public $keyname;

        public function __construct()
        {
            $this->keyname = 'sadad';

            add_filter('edd_payment_gateways', array($this, 'add'));
            add_action($this->format('edd_{key}_cc_form'), array($this, 'cc_form'));
            add_action($this->format('edd_gateway_{key}'), array($this, 'process'));
            add_action($this->format('edd_verify_{key}'), array($this, 'verify'));
            add_filter('edd_settings_gateways', array($this, 'settings'));
            add_action('init', array($this, 'listen'));
        }

        public function add($gateways)
        {
            global $edd_options;
            $gateways[$this->keyname] = array(
                'checkout_label' => isset($edd_options['sadad_label']) ? $edd_options['sadad_label'] : 'درگاه سداد بانک ملی',
                'admin_label' => 'سداد'
            );
            return $gateways;
        }

        public function cc_form()
        {
            return ;
        }

        public function process($purchase_data)
        {
            global $edd_options;
            $payment = $this->insert_payment($purchase_data);

            if ($payment) {

                $terminal_id = (isset($edd_options[$this->keyname . '_terminal_id']) ? $edd_options[$this->keyname . '_terminal_id'] : '');
                $merchant_id = (isset($edd_options[$this->keyname . '_merchant_id']) ? $edd_options[$this->keyname . '_merchant_id'] : '');
                $terminal_key = (isset($edd_options[$this->keyname . '_terminal_key']) ? $edd_options[$this->keyname . '_terminal_key'] : '');
                $callback = add_query_arg('verify_' . $this->keyname, '1', get_permalink($edd_options['success_page']));
                $orderId = $payment;
                $amount = intval($purchase_data['price']);
                if (edd_get_currency() == 'IRT')
                    $amount = $amount * 10;

                $sign_data = $this->sadad_encrypt($terminal_id . ';' . $orderId . ';' . $amount, $terminal_key);

                $parameters = array(
                    'MerchantID' => $merchant_id,
                    'TerminalId' => $terminal_id,
                    'Amount' => $amount,
                    'OrderId' => $orderId,
                    'LocalDateTime' => date('Ymdhis'),
                    'ReturnUrl' => $callback,
                    'SignData' => $sign_data,
                );

                $result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);

                if ($result != false) {
                    if ($result->ResCode == 0) {
                        echo '<form id="redirect_to_melli" method="get" action="https://sadad.shaparak.ir/VPG/Purchase" style="display:none !important;"  >
										<input type="hidden"  name="Token" value="' . $result->Token . '" />
										<input type="submit" value="Pay"/>
									</form>
									<script language="JavaScript" type="text/javascript">
										document.getElementById("redirect_to_melli").submit();
									</script>';

                    }
                    else {
                        edd_insert_payment_note($payment, ' خطای CURL#');
                        edd_update_payment_status($payment, 'failed');
                        edd_set_error('sadad_connect_error', 'خطا در برقراری ارتباط با بانک! ' . $this->sadad_request_err_msg($result->ResCode));
                        edd_send_back_to_checkout();
                        return false;
                    }
                }
                else {
                    edd_insert_payment_note($payment, $this->sadad_request_err_msg($result->ResCode));
                    edd_update_payment_status($payment, 'failed');
                    edd_set_error('sadad_connect_error', 'خطا در برقراری ارتباط با بانک! ' . $this->sadad_request_err_msg($result->ResCode));
                    edd_send_back_to_checkout();
                    return false;
                }
            }
            else {
                edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
            }
        }

        public function verify()
        {
            global $edd_options;

            $payment = edd_get_payment($_POST['OrderId']);

            if (!$payment) {
                wp_die('خطا در یافتن سفارش !');
            }
            if ($payment->status == 'complete')
                return false;

            $terminal_key = (isset($edd_options[$this->keyname . '_terminal_key']) ? $edd_options[$this->keyname . '_terminal_key'] : '');

            if (isset($_POST['token']) && isset($_POST['OrderId']) && isset($_POST['ResCode'])) {

                $token = $_POST['token'];
                $parameters = array(
                    'Token' => $token,
                    'SignData' => $this->sadad_encrypt($token, $terminal_key),
                );

                $result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);

                edd_empty_cart();

                if (version_compare(EDD_VERSION, '2.1', '>='))
                    edd_set_payment_transaction_id($payment->ID, $result->SystemTraceNo);

                edd_insert_payment_note($payment->ID, 'شماره تراکنش بانکی: ' . $result->SystemTraceNo);
                edd_update_payment_meta($payment->ID, 'sadad_ref_num', $result->RetrivalRefNo);
                edd_update_payment_status($payment->ID, 'publish');
                edd_send_to_success_page();

                if ($result != false) {
                    if ($result->ResCode == 0) {
                        edd_empty_cart();

                        if (version_compare(EDD_VERSION, '2.1', '>='))
                            edd_set_payment_transaction_id($payment->ID, $result->SystemTraceNo);

                        edd_insert_payment_note($payment->ID, 'شماره تراکنش بانکی: ' . $result->SystemTraceNo);
                        edd_update_payment_meta($payment->ID, 'sadad_ref_num', $result->RetrivalRefNo);
                        edd_update_payment_status($payment->ID, 'publish');
                        edd_send_to_success_page();
                    }
                    else {
                        edd_update_payment_status($payment->ID, 'failed');
                        wp_redirect(get_permalink($edd_options['failure_page']));
                        exit(1);
                    }
                }
                else {
                    edd_update_payment_status($payment->ID, 'failed');
                    wp_redirect(get_permalink($edd_options['failure_page']));
                    exit(1);
                }
            }
        }

        private function mcrypt_encrypt_pkcs7($str, $key)
        {
            $block = mcrypt_get_block_size("tripledes", "ecb");
            $pad = $block - (strlen($str) % $block);
            $str .= str_repeat(chr($pad), $pad);
            $ciphertext = mcrypt_encrypt("tripledes", $key, $str, "ecb");
            return base64_encode($ciphertext);
        }

        private function openssl_encrypt_pkcs7($key, $data)
        {
            $encData = openssl_encrypt($data, 'des-ede3', $key, 0);
            return $encData;
        }


        private function sadad_encrypt($data, $key)
        {
            $key = base64_decode($key);
            if (function_exists('openssl_encrypt')) {
                return $this->openssl_encrypt_pkcs7($key, $data);
            } elseif (function_exists('mcrypt_encrypt')) {
                return $this->mcrypt_encrypt_pkcs7($data, $key);
            } else {
                require_once './TripleDES.php';
                $cipher = new Crypt_TripleDES();
                return $cipher->letsEncrypt($key, $data);
            }

        }

        private function sadad_call_api($url, $data = false)
        {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
                curl_setopt($ch, CURLOPT_POST, 1);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                $result = curl_exec($ch);
                curl_close($ch);
                return !empty($result) ? json_decode($result) : false;
            }
            catch (Exception $ex) {
                return false;
            }
        }

        public function settings($settings)
        {
            return array_merge($settings, array(
                $this->keyname . '_header' => array(
                    'id' => $this->keyname . '_header',
                    'type' => 'header',
                    'name' => '<strong>تنظیمات درگاه سداد بانک ملی</strong>'
                ),
                $this->keyname . '_merchant_id' => array(
                    'id' => $this->keyname . '_merchant_id',
                    'name' => 'شماره پذیرنده',
                    'type' => 'text',
                    'size' => 'regular'
                ),
                $this->keyname . '_terminal_id' => array(
                    'id' => $this->keyname . '_terminal_id',
                    'name' => 'شماره ترمینال',
                    'type' => 'text',
                    'size' => 'regular'
                ),
                $this->keyname . '_terminal_key' => array(
                    'id' => $this->keyname . '_terminal_key',
                    'name' => 'کلید تراکنش',
                    'type' => 'text',
                    'size' => 'regular'
                ),
                $this->keyname . '_label' => array(
                    'id' => $this->keyname . '_label',
                    'name' => 'نام درگاه در صفحه پرداخت',
                    'type' => 'text',
                    'size' => 'regular',
                    'std' => 'درگاه سداد بانک ملی'
                )
            ));
        }

        private function format($string)
        {
            return str_replace('{key}', $this->keyname, $string);
        }

        private function insert_payment($purchase_data)
        {
            global $edd_options;

            $payment_data = array(
                'price' => $purchase_data['price'],
                'date' => $purchase_data['date'],
                'user_email' => $purchase_data['user_email'],
                'purchase_key' => $purchase_data['purchase_key'],
                'currency' => $edd_options['currency'],
                'downloads' => $purchase_data['downloads'],
                'user_info' => $purchase_data['user_info'],
                'cart_details' => $purchase_data['cart_details'],
                'status' => 'pending'
            );
            $payment = edd_insert_payment($payment_data);

            return $payment;
        }

        public function listen()
        {
            if (isset($_GET['verify_' . $this->keyname]) && $_GET['verify_' . $this->keyname])
                do_action('edd_verify_' . $this->keyname);
        }

        private function sadad_request_err_msg($err_code)
        {

            $message = 'در حین پرداخت خطای سیستمی رخ داده است .';
            switch ($err_code) {
                case 3:
                    $message = 'پذيرنده کارت فعال نیست لطفا با بخش امورپذيرندگان, تماس حاصل فرمائید.';
                    break;
                case 23:
                    $message = 'پذيرنده کارت نامعتبر است لطفا با بخش امورذيرندگان, تماس حاصل فرمائید.';
                    break;
                case 58:
                    $message = 'انجام تراکنش مربوطه توسط پايانه ی انجام دهنده مجاز نمی باشد.';
                    break;
                case 61:
                    $message = 'مبلغ تراکنش از حد مجاز بالاتر است.';
                    break;
                case 1000:
                    $message = 'ترتیب پارامترهای ارسالی اشتباه می باشد, لطفا مسئول فنی پذيرنده با بانکماس حاصل فرمايند.';
                    break;
                case 1001:
                    $message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,پارامترهای پرداختاشتباه می باشد.';
                    break;
                case 1002:
                    $message = 'خطا در سیستم- تراکنش ناموفق';
                    break;
                case 1003:
                    $message = 'آی پی پذیرنده اشتباه است. لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند.';
                    break;
                case 1004:
                    $message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,شماره پذيرندهاشتباه است.';
                    break;
                case 1005:
                    $message = 'خطای دسترسی:لطفا بعدا تلاش فرمايید.';
                    break;
                case 1006:
                    $message = 'خطا در سیستم';
                    break;
                case 1011:
                    $message = 'درخواست تکراری- شماره سفارش تکراری می باشد.';
                    break;
                case 1012:
                    $message = 'اطلاعات پذيرنده صحیح نیست,يکی از موارد تاريخ,زمان يا کلید تراکنش
						اشتباه است.لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند.';
                    break;
                case 1015:
                    $message = 'پاسخ خطای نامشخص از سمت مرکز';
                    break;
                case 1017:
                    $message = 'مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است';
                    break;
                case 1018:
                    $message = 'اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید';
                    break;
                case 1019:
                    $message = 'امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست';
                    break;
                case 1020:
                    $message = 'پذيرنده غیرفعال شده است.لطفا جهت فعال سازی با بانک تماس بگیريد';
                    break;
                case 1023:
                    $message = 'آدرس بازگشت پذيرنده نامعتبر است';
                    break;
                case 1024:
                    $message = 'مهر زمانی پذيرنده نامعتبر است';
                    break;
                case 1025:
                    $message = 'امضا تراکنش نامعتبر است';
                    break;
                case 1026:
                    $message = 'شماره سفارش تراکنش نامعتبر است';
                    break;
                case 1027:
                    $message = 'شماره پذيرنده نامعتبر است';
                    break;
                case 1028:
                    $message = 'شماره ترمینال پذيرنده نامعتبر است';
                    break;
                case 1029:
                    $message = 'آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
                    break;
                case 1030:
                    $message = 'آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
                    break;
                case 1031:
                    $message = 'مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمايید .';
                    break;
                case 1032:
                    $message = 'پرداخت با اين کارت . برای پذيرنده مورد نظر شما امکان پذير نیست.لطفا از کارتهای مجاز که توسط پذيرنده معرفی شده است . استفاده نمايید.';
                    break;
                case 1033:
                    $message = 'به علت مشکل در سايت پذيرنده. پرداخت برای اين پذيرنده غیرفعال شده
						است.لطفا مسوول فنی سايت پذيرنده با بانک تماس حاصل فرمايند.';
                    break;
                case 1036:
                    $message = 'اطلاعات اضافی ارسال نشده يا دارای اشکال است';
                    break;
                case 1037:
                    $message = 'شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد';
                    break;
                case 1053:
                    $message = 'خطا: درخواست معتبر, از سمت پذيرنده صورت نگرفته است لطفا اطلاعات پذيرنده خود را چک کنید.';
                    break;
                case 1055:
                    $message = 'مقدار غیرمجاز در ورود اطلاعات';
                    break;
                case 1056:
                    $message = 'سیستم موقتا قطع میباشد.لطفا بعدا تلاش فرمايید.';
                    break;
                case 1058:
                    $message = 'سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفا بعدا سعی بفرمايید.';
                    break;
                case 1061:
                    $message = 'اشکال در تولید کد يکتا. لطفا مرورگر خود را بسته و با اجرای مجدد مرورگر « عملیات پرداخت را انجام دهید )احتمال استفاده از دکمه Back » مرورگر(';
                    break;
                case 1064:
                    $message = 'لطفا مجددا سعی بفرمايید';
                    break;
                case 1065:
                    $message = 'ارتباط ناموفق .لطفا چند لحظه ديگر مجددا سعی کنید';
                    break;
                case 1066:
                    $message = 'سیستم سرويس دهی پرداخت موقتا غیر فعال شده است';
                    break;
                case 1068:
                    $message = 'با عرض پوزش به علت بروزرسانی . سیستم موقتا قطع میباشد.';
                    break;
                case 1072:
                    $message = 'خطا در پردازش پارامترهای اختیاری پذيرنده';
                    break;
                case 1101:
                    $message = 'مبلغ تراکنش نامعتبر است';
                    break;
                case 1103:
                    $message = 'توکن ارسالی نامعتبر است';
                    break;
                case 1104:
                    $message = 'اطلاعات تسهیم صحیح نیست';
                    break;
                default:
                    $message = 'خطای نامشخص';
            }
            return __($message, 'woocommerce');
        }

        private function sadad_verify_err_msg($res_code)
        {
            $error_text = '';
            switch ($res_code) {
                case -1:
                case '-1':
                    $error_text = 'پارامترهای ارسالی صحیح نیست و يا تراکنش در سیستم وجود ندارد.';
                    break;
                case 101:
                case '101':
                    $error_text = 'مهلت ارسال تراکنش به پايان رسیده است.';
                    break;
            }
            return __($error_text, 'woocommerce');
        }
    }

endif;

new EDD_sadad_Gateway;