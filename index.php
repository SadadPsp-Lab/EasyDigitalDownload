<?php

	/**
	 * Plugin Name: درگاه بانک ملی EDD
	 * Plugin URI: https://sadadpsp.ir
	 * Description: درگاه بانک ملی برای Easy Digital Download
	 * Version: 1.0
	 * Author: http://almaatech.ir
	 * Author URI: http://almaatech.ir
	 */

	if (!function_exists('edd_rial')) {

		function edd_rial($formatted, $currency, $price) {
			return $price . ' ریال';
		}

	}
	add_filter('edd_rial_currency_filter_after', 'edd_rial', 10, 3);
	@session_start();

	function zpw_edd_rial($formatted, $currency, $price) {
		return $price . 'ریال';
	}

	function add_sadad_gateway($gateways) {
		$gateways['sadad'] = array(
				'admin_label' => 'بانک ملی',
				'checkout_label' => 'درگاه بانک ملی'
		);

		return $gateways;
	}

	add_filter('edd_payment_gateways', 'add_sadad_gateway');

	function sdd_cc_form() {
		return;
	}

	add_action('edd_sadad_cc_form', 'sdd_cc_form');

	function sdd_process($purchase_data) {
		global $edd_options;

		$payment_data = array(
				'price' => $purchase_data['price'],
				'date' => $purchase_data['date'],
				'user_email' => $purchase_data['post_data']['edd_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency' => $edd_options['currency'],
				'downloads' => $purchase_data['downloads'],
				'cart_details' => $purchase_data['cart_details'],
				'user_info' => $purchase_data['user_info'],
				'status' => 'pending'
		);
		$payment = edd_insert_payment($payment_data);

		if ($payment) {
			delete_transient('edd_sadad_record');
			set_transient('edd_sadad_record', $payment);

			$_SESSION['edd_sadad_record'] = $payment;
			$callback = add_query_arg('verify', 'sadad', get_permalink($edd_options['success_page']));
			$amount = intval($payment_data['price']);
			$order_id = mt_rand(1000000, 5000000) * 2;
			$sign_data = sadad_encrypt($edd_options['sdd_terminal_id'] . ';' . $order_id . ';' . $amount, $edd_options['sdd_terminal_key']);
			$parameters = array(
					'MerchantID' => $edd_options['sdd_merchant_id'],
					'TerminalId' => $edd_options['sdd_terminal_id'],
					'Amount' => $amount,
					'OrderId' => $order_id,
					'LocalDateTime' => date('Ymdhis'),
					'ReturnUrl' => $callback,
					'SignData' => $sign_data,
			);

			$error_flag = false;
			$error_msg = '';

			$result = sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);

			if ($result != false) {
				if ($result->ResCode == 0) {
					edd_insert_payment_note($payment, "کد پاسخ درگاه بانک ملی: {$result->ResCode} و کد پرداخت: {$result->Token}");
					wp_redirect('https://sadad.shaparak.ir/VPG/Purchase?Token=' . $result->Token);
					exit;
				} else {
					//bank returned an error
					$error_flag = true;
					$error_msg = 'خطا در انتقال به بانک! ' . sadad_request_err_msg($result->ResCode);
				}
			} else {
				// couldn't connect to bank
				$error_flag = true;
				$error_msg = 'خطا! برقراری ارتباط با بانک امکان پذیر نیست.';
			}
			if ($error_flag) {
				edd_insert_payment_note($payment, __($error_msg, 'edd'));
				wp_die(__($error_msg, 'edd'));
			}

		} else {
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		}
	}

	add_action('edd_gateway_sadad', 'sdd_process');

	function sdd_verify() {
		global $edd_options;

		if (isset($_GET['verify']) && $_GET['verify'] == 'sadad') {


			$payment_id = $_SESSION['edd_sadad_record'];
			// get_transient( 'edd_sadad_record' );
			//delete_transient( 'edd_sadad_record' );

			if (isset($_POST['token']) && isset($_POST['OrderId']) && isset($_POST['ResCode'])) {
				if ($_POST['ResCode'] == 0) {
					$token = $_POST['token'];

					//verify payment
					$parameters = array(
							'Token' => $token,
							'SignData' => sadad_encrypt($token, $edd_options['sdd_terminal_key']),
					);

					$error_flag = false;
					$error_msg = '';

					$result = sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);

					if ($result != false) {
						if ($result->ResCode == 0) {
							//update_post_meta( $payment, '_edd_payment_ppalrefnum',$Refnumber);
							$RetrivalRefNo = $result->RetrivalRefNo;
							$TraceNo = $result->SystemTraceNo;

							edd_insert_payment_note($payment_id, "پرداخت شما با موفقیت و شماره مرجع {$RetrivalRefNo} و شماره پیگیری {$TraceNo} انجام شد.");

							edd_update_payment_status($payment_id, 'publish');
							edd_send_to_success_page();
						} else {
							//couldn't verify the payment due to a back error
							$error_flag = true;
							$error_msg = 'خطا هنگام پرداخت! ' . sadad_verify_err_msg($result->ResCode);
						}
					} else {
						//couldn't verify the payment due to a connection failure to bank
						$error_flag = true;
						$error_msg = 'خطا! عدم امکان دریافت تاییدیه پرداخت از بانک';
					}

					if ($error_flag) {
						// todo: error message need to be logged
						edd_update_payment_status($payment_id, 'failed');
						wp_redirect(get_permalink($edd_options['failure_page']));
					}
					exit;
				} else {
					edd_update_payment_status($payment_id, 'revoked');
					wp_redirect(get_permalink($edd_options['failure_page']));
					exit;
				}

			} else {
				edd_update_payment_status($payment_id, 'revoked');
				wp_redirect(get_permalink($edd_options['failure_page']));
				exit;
			}

		}
	}

	add_action('init', 'sdd_verify');

	function sdd_settings($settings) {
		$sadad_options = array(
				array(
						'id' => 'sadad_settings',
						'type' => 'header',
						'name' => 'پیکربندی درگاه بانک ملی - <a href="https://sadadpsp.ir/">EDD Persian</a> &ndash; <a href="mailto:info@sadadpsp.ir">گزارش خرابی</a>'
				),
				array(
						'id' => 'sdd_merchant_id',
						'type' => 'text',
						'name' => 'شماره پذیرنده',
						'desc' => 'شماره پذیرنده درگاه بانک ملی'
				),
				array(
						'id' => 'sdd_terminal_id',
						'type' => 'text',
						'name' => 'شماره ترمینال',
						'desc' => 'شماره ترمینال درگاه بانک ملی'
				),
				array(
						'id' => 'sdd_terminal_key',
						'type' => 'text',
						'name' => 'کلید تراکنش',
						'desc' => 'کلید تراکنش درگاه بانک ملی'
				),

		);

		return array_merge($settings, $sadad_options);
	}


	add_filter('edd_settings_gateways', 'sdd_settings');


    //Create sign data(Tripledes(ECB,PKCS7)) using mcrypt
    function mcrypt_encrypt_pkcs7($str, $key) {
        $block = mcrypt_get_block_size("tripledes", "ecb");
        $pad = $block - (strlen($str) % $block);
        $str .= str_repeat(chr($pad), $pad);
        $ciphertext = mcrypt_encrypt("tripledes", $key, $str,"ecb");
        return base64_encode($ciphertext);
    }

    //Create sign data(Tripledes(ECB,PKCS7)) using openssl
    function openssl_encrypt_pkcs7($key, $data) {
        $ivlen = openssl_cipher_iv_length('des-ede3');
        $iv = openssl_random_pseudo_bytes($ivlen);
        $encData = openssl_encrypt($data, 'des-ede3', $key, 0, $iv);
        return $encData;
    }

    function sadad_encrypt($data, $key) {
        $key = base64_decode($key);
        if( function_exists('openssl_encrypt') ) {
            return openssl_encrypt_pkcs7($key, $data);
        } elseif( function_exists('mcrypt_encrypt') ) {
            return mcrypt_encrypt_pkcs7($data, $key);
        } else {
            require_once 'TripleDES.php';
            $cipher = new Crypt_TripleDES();
            return $cipher->letsEncrypt($key, $data);
        }

    }



	function sadad_call_api($url, $data = false) {
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

	function sadad_request_err_msg($err_code) {

		$message = __('در حین پرداخت خطای سیستمی رخ داده است .', 'woocommerce');
		switch ($err_code) {
			case 3:
				$message = __('پذيرنده کارت فعال نیست لطفا با بخش امورپذيرندگان, تماس حاصل فرمائید.', 'woocommerce');
				break;
			case 23:
				$message = __('پذيرنده کارت نامعتبر است لطفا با بخش امورذيرندگان, تماس حاصل فرمائید.', 'woocommerce');
				break;
			case 58:
				$message = __('انجام تراکنش مربوطه توسط پايانه ی انجام دهنده مجاز نمی باشد.', 'woocommerce');
				break;
			case 61:
				$message = __('مبلغ تراکنش از حد مجاز بالاتر است.', 'woocommerce');
				break;
			case 1000:
				$message = __('ترتیب پارامترهای ارسالی اشتباه می باشد, لطفا مسئول فنی پذيرنده با بانکماس حاصل فرمايند.', 'woocommerce');
				break;
			case 1001:
				$message = __('لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,پارامترهای پرداختاشتباه می باشد.', 'woocommerce');
				break;
			case 1002:
				$message = __('خطا در سیستم- تراکنش ناموفق', 'woocommerce');
				break;
			case 1003:
				$message = __('آی پی پذیرنده اشتباه است. لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند.', 'woocommerce');
				break;
			case 1004:
				$message = __('لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,شماره پذيرندهاشتباه است.', 'woocommerce');
				break;
			case 1005:
				$message = __('خطای دسترسی:لطفا بعدا تلاش فرمايید.', 'woocommerce');
				break;
			case 1006:
				$message = __('خطا در سیستم', 'woocommerce');
				break;
			case 1011:
				$message = __('درخواست تکراری- شماره سفارش تکراری می باشد.', 'woocommerce');
				break;
			case 1012:
				$message = __('اطلاعات پذيرنده صحیح نیست,يکی از موارد تاريخ,زمان يا کلید تراکنش
				اشتباه است.لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند.', 'woocommerce');
				break;
			case 1015:
				$message = __('پاسخ خطای نامشخص از سمت مرکز', 'woocommerce');
				break;
			case 1017:
				$message = __('مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است', 'woocommerce');
				break;
			case 1018:
				$message = __('اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید', 'woocommerce');
				break;
			case 1019:
				$message = __('امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست', 'woocommerce');
				break;
			case 1020:
				$message = __('پذيرنده غیرفعال شده است.لطفا جهت فعال سازی با بانک تماس بگیريد', 'woocommerce');
				break;
			case 1023:
				$message = __('آدرس بازگشت پذيرنده نامعتبر است', 'woocommerce');
				break;
			case 1024:
				$message = __('مهر زمانی پذيرنده نامعتبر است', 'woocommerce');
				break;
			case 1025:
				$message = __('امضا تراکنش نامعتبر است', 'woocommerce');
				break;
			case 1026:
				$message = __('شماره سفارش تراکنش نامعتبر است', 'woocommerce');
				break;
			case 1027:
				$message = __('شماره پذيرنده نامعتبر است', 'woocommerce');
				break;
			case 1028:
				$message = __('شماره ترمینال پذيرنده نامعتبر است', 'woocommerce');
				break;
			case 1029:
				$message = __('آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند', 'woocommerce');
				break;
			case 1030:
				$message = __('آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند', 'woocommerce');
				break;
			case 1031:
				$message = __('مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمايید .', 'woocommerce');
				break;
			case 1032:
				$message = __('پرداخت با اين کارت . برای پذيرنده مورد نظر شما امکان پذير نیست.لطفا از کارتهای مجاز که توسط پذيرنده معرفی شده است . استفاده نمايید.', 'woocommerce');
				break;
			case 1033:
				$message = __('به علت مشکل در سايت پذيرنده. پرداخت برای اين پذيرنده غیرفعال شده
				است.لطفا مسوول فنی سايت پذيرنده با بانک تماس حاصل فرمايند.', 'woocommerce');
				break;
			case 1036:
				$message = __('اطلاعات اضافی ارسال نشده يا دارای اشکال است', 'woocommerce');
				break;
			case 1037:
				$message = __('شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد', 'woocommerce');
				break;
			case 1053:
				$message = __('خطا: درخواست معتبر, از سمت پذيرنده صورت نگرفته است لطفا اطلاعات پذيرنده خود را چک کنید.', 'woocommerce');
				break;
			case 1055:
				$message = __('مقدار غیرمجاز در ورود اطلاعات', 'woocommerce');
				break;
			case 1056:
				$message = __('سیستم موقتا قطع میباشد.لطفا بعدا تلاش فرمايید.', 'woocommerce');
				break;
			case 1058:
				$message = __('سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفا بعدا سعی بفرمايید.', 'woocommerce');
				break;
			case 1061:
				$message = __('اشکال در تولید کد يکتا. لطفا مرورگر خود را بسته و با اجرای مجدد مرورگر « عملیات پرداخت را انجام دهید )احتمال استفاده از دکمه Back » مرورگر(', 'woocommerce');
				break;
			case 1064:
				$message = __('لطفا مجددا سعی بفرمايید', 'woocommerce');
				break;
			case 1065:
				$message = __('ارتباط ناموفق .لطفا چند لحظه ديگر مجددا سعی کنید', 'woocommerce');
				break;
			case 1066:
				$message = __('سیستم سرويس دهی پرداخت موقتا غیر فعال شده است', 'woocommerce');
				break;
			case 1068:
				$message = __('با عرض پوزش به علت بروزرسانی . سیستم موقتا قطع میباشد.', 'woocommerce');
				break;
			case 1072:
				$message = __('خطا در پردازش پارامترهای اختیاری پذيرنده', 'woocommerce');
				break;
			case 1101:
				$message = __('مبلغ تراکنش نامعتبر است', 'woocommerce');
				break;
			case 1103:
				$message = __('توکن ارسالی نامعتبر است', 'woocommerce');
				break;
			case 1104:
				$message = __('اطلاعات تسهیم صحیح نیست', 'woocommerce');
				break;
			default:
				$message = __('خطای نامشخص', 'woocommerce');
		}
		return __($message, 'edd');

	}

	function sadad_verify_err_msg($res_code) {
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
		return __($error_text, 'edd');
	}
