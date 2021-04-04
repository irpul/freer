<?
	$pluginData[irpul][type] = 'payment';
	$pluginData[irpul][name] = 'درگاه پرداخت ایرپول';
	$pluginData[irpul][uniq] = 'irpul';
	$pluginData[irpul][description] = 'پلاگین اتصال به درگاه های بانکی';
	$pluginData[irpul][author][name] = 'ایرپول';
	$pluginData[irpul][author][url] = 'https://irpul.ir';
	$pluginData[irpul][author][email] = 'info@irpul.ir';
	$pluginData[irpul][field][config][2][title] = 'توکن درگاه';
	$pluginData[irpul][field][config][2][name] = 'token';

	function gateway__irpul($data){
		global $config,$smarty,$payment,$db;
		
		$product_id = $payment[payment_product];
		$row_products	= $db->fetch("SELECT * FROM product WHERE product_id = '$product_id' ");
		$row_cat		= $db->fetchAll("SELECT category_title FROM category");
		$cats=''; $i= 0;
		$count 	= count($row_cat);
		foreach($row_cat as $cat){
			$cats .= $cat['category_title'];
			if ($i!=$count-1) {	$cats .= '،';	}	$i++;
			//$data[$plugindata['plugindata_field_name']] = $plugindata['plugindata_field_value'];
		}
		
		if(  $payment[payment_user]!='' ){
			$payment_user = ' | user: '. $payment['payment_user'];
		}
		
		$parameters = array(
			'method' 		=> 'payment',
			//'plugin' 		=> 'Freer',
			'order_id'		=> $data[invoice_id],
			'product'		=> ' تعداد: '. $payment[payment_qty] . ' ' . $row_products[product_title] . ' | توضیح:' . $row_products[product_body],
			'payer_name'	=> '',
			'phone' 		=> '',
			'mobile' 		=> $payment[payment_mobile],
			'email' 		=> $payment[payment_email],
			'amount' 		=> $data[amount],
			'callback_url' 	=> $data[callback],
			'address' 		=> '',
			'description' 	=> 'آی پی: ' . $payment[payment_ip] .' '. $payment_user . ' دسته: ' . $cats,
			'test_mode' 	=> false,
		);
		
		$token 	= $data[token];
		$result = post_data('https://irpul.ir/ws.php', $parameters, $token );

		if( isset($result['http_code']) ){
			$data =  json_decode($result['data'],true);

			if( isset($data['code']) && $data['code'] === 1){
				$url = $data['url'];
				header("Location: $url");
				exit;
			}
			else{
				$data[title] = 'خطا در برقراری ارتباط با ایرپول';
				$data[message] = 'Error Code: ' . $data['code'] . "<br/>" . $data['status'];
				
				$data[message]	.=	'<br /><a href="index.php" class="button">بازگشت</a>';
				$smarty->assign('data', $data );
				$smarty->display('message.tpl');
				exit;
			}
		}else{
			$data[title] = 'خطا در برقراری ارتباط با ایرپول';
			$data[message] = 'پاسخی از سرویس دهنده دریافت نشد. لطفا دوباره تلاش نمائید';
			
			$data[message]	.=	'<br /><a href="index.php" class="button">بازگشت</a>';
			$smarty->assign('data', $data );
			$smarty->display('message.tpl');
			exit;
		}
	}
	
	function post_data($url,$params,$token) {
		ini_set('default_socket_timeout', 15);

		$headers = array(
			"Authorization: token= {$token}",
			'Content-type: application/json'
		);

		$handle = curl_init($url);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($handle, CURLOPT_TIMEOUT, 40);

		curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($params) );
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers );

		$response = curl_exec($handle);
		//error_log('curl response1 : '. print_r($response,true));

		$msg='';
		$http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));

		$status= true;

		if ($response === false) {
			$curl_errno = curl_errno($handle);
			$curl_error = curl_error($handle);
			$msg .= "Curl error $curl_errno: $curl_error";
			$status = false;
		}

		curl_close($handle);//dont move uppder than curl_errno

		if( $http_code == 200 ){
			$msg .= "Request was successfull";
		}
		else{
			$status = false;
			if ($http_code == 400) {
				$status = true;
			}
			elseif ($http_code == 401) {
				$msg .= "Invalid access token provided";
			}
			elseif ($http_code == 502) {
				$msg .= "Bad Gateway";
			}
			elseif ($http_code >= 500) {// do not wat to DDOS server if something goes wrong
				sleep(2);
			}
		}

		$res['http_code'] 	= $http_code;
		$res['status'] 		= $status;
		$res['msg'] 		= $msg;
		$res['data'] 		= $response;

		if(!$status){
			//error_log(print_r($res,true));
		}
		return $res;
	}

	
	function url_decrypt($string){
		$counter = 0;
		$data = str_replace(array('-','_','.'),array('+','/','='),$string);
		$mod4 = strlen($data) % 4;
		if ($mod4) {
			$data .= substr('====', $mod4);
		}
		$decrypted = base64_decode($data);
		
		$check = array('trans_id','order_id','amount','refcode','status');
		foreach($check as $str){
			str_replace($str,'',$decrypted,$count);
			if($count > 0){
				$counter++;
			}
		}
		if($counter === 5){
			return array('data'=>$decrypted , 'status'=>true);
		}else{
			return array('data'=>'' , 'status'=>false);
		}
	}
	
	//-- تابع بررسی وضعیت پرداخت
	function callback__irpul($data){
		global $db,$post,$_POST,$_GET;
		$token = $data['token'];
		
		$resCode 	= $_POST['ResCode'];
		
		$irpul_token 	= $_GET['irpul_token'];
		$decrypted 		= url_decrypt( $irpul_token );
		if($decrypted['status']){
			parse_str($decrypted['data'], $ir_output);
			$trans_id 	= $ir_output['trans_id'];
			$order_id 	= $ir_output['order_id'];
			$amount 	= $ir_output['amount'];
			$refcode	= $ir_output['refcode'];
			$status 	= $ir_output['status'];
			
			if($status == 'paid'){
				$payment 		= $db->fetch("SELECT * FROM payment WHERE payment_rand='$order_id' LIMIT 1;");
				$payment_id 	= $payment[payment_id];
				$payment_amount = $payment[payment_amount];
				$payment_status = $payment[payment_status];
				
				if ( $payment_status== 1){
					$parameters = array(
						'method' 	    => 'verify',
						'trans_id' 		=> $trans_id,
						'amount'	 	=> $payment_amount,
					);
					
					$result =  post_data('https://irpul.ir/ws.php', $parameters, $token );
					
					if( isset($result['http_code']) ){
						$data =  json_decode($result['data'],true);

						if( isset($data['code']) && $data['code'] === 1){
							//-- آماده کردن خروجی
							$output[status]		= 1;
							$output[res_num]	= $trans_id;
							$output[ref_num]	= $refcode;
							$output[payment_id]	= $payment_id;
						}
						else{
							$output[status]		= 0;
							$output[message]	= 'خطا در پرداخت. کد خطا: ' . $data['code'] . '\r\n ' . $data['status'];
						}
					}else{
						$output[status]		= 0;
						$output[message]	= "پاسخی از سرویس دهنده دریافت نشد. لطفا دوباره تلاش نمائید";
					}
				}
				else{
					//-- سفارش قبلا پرداخت شده است.
					$output[status]	= 0;
					$output[message]= 'این سفارش قبلا پرداخت شده است.';
				}
			}
			else{
				$output[status]	= 0;
				$output[message]= 'پرداخت با موفقيت انجام نشده است.';
			}
		}
		return $output;
	}
