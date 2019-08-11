<?
	$pluginData[irpul][type] = 'payment';
	$pluginData[irpul][name] = 'درگاه پرداخت ایرپول';
	$pluginData[irpul][uniq] = 'irpul';
	$pluginData[irpul][description] = 'پلاگین اتصال به درگاه های بانکی';
	$pluginData[irpul][author][name] = 'ایرپول';
	$pluginData[irpul][author][url] = 'http://irpul.ir';
	$pluginData[irpul][author][email] = 'info@irpul.ir';
	$pluginData[irpul][field][config][2][title] = 'شناسه درگاه';
	$pluginData[irpul][field][config][2][name] = 'webgate_id';

	function gateway__irpul($data){
		global $config,$smarty,$payment,$db;
		
		$product_id = $payment[payment_product];
		$row_products	= $db->fetch("SELECT * FROM product WHERE product_id = '$product_id' ");
		$row_cat		= $db->fetchAll("SELECT category_title FROM category");
		$cats=''; $i= 0;
		$count 	= count($row_cat);
		foreach($row_cat as $cat)
		{
			$cats .= $cat['category_title'];
			if ($i!=$count-1) {	$cats .= '،';	}	$i++;
			//$data[$plugindata['plugindata_field_name']] = $plugindata['plugindata_field_value'];
		}
		
		if(  $payment[payment_user]!='' ){
			$payment_user = ' | user: '. $payment['payment_user'];
		}
		$parameters = array
		(
			'plugin' 		=> 'Freer',
			'webgate_id' 	=> $data[webgate_id],
			'order_id'		=> $data[invoice_id],
			'product'		=> ' تعداد: '. $payment[payment_qty] . ' ' . $row_products[product_title] . ' | توضیح:' . $row_products[product_body],
			'payer_name'	=> '',
			'phone' 		=> '',
			'mobile' 		=> $payment[payment_mobile],
			'email' 		=> $payment[payment_email],
			'amount' 		=> $data[amount],
			'callback_url' 	=> $data[callback],
			'address' 		=> '',
			'description' 	=> 'آی پی: ' . $payment[payment_ip] .' '. $payment_user . ' دسته: ' . $cats
		);

		try {
			$client = new SoapClient('https://irpul.ir/webservice.php?wsdl' , array('soap_version'=>'SOAP_1_2','encoding'=>'UTF-8'));
			$result = $client->Payment($parameters);
		}catch (Exception $e) { echo 'Error'. $e->getMessage();  }
		
		if( isset($result) && $result['res_code']===1 ){
			$url = $result['url'];
			header("Location: $url");
			exit;
		}else{
			//-- نمایش خطا
			$data[title] = 'خطا در برقراری ارتباط با ایرپول';
			$data[message] = '<span style="color:red" >Error Code: '. $result['res_code'] . ' ' . $result['status'] .'</span>';
			
			switch(intval($result['res_code']))
			{
				case -1:$data[message] .='<br> شناسه درگاه خالی است';break;
				case -2:$data[message] .='<br> شناسه درگاه اشتباه است';break;
				case -3:$data[message] .='<br> حساب پذیرنده تایید نشده است';break;
				case -4:$data[message] .='<br> مبلغ خالی است';break;			
				case -5:$data[message] .='<br> مبلغ اشتباه است';break;
				case -6:$data[message] .='<br> شماره سفارش صحیح نیست';break;
				case -7:$data[message] .='<br> لینک بازگشت خالی است';break;
				case -8:$data[message] .='<br> لینک بازگشت صحیح نیست';break;
				case -9:$data[message] .='<br> آدرس ایمیل صحیح نیست';break;
				case -10:$data[message] .='<br> شماره تلفن صحیح نیست';break;
				case -11:$data[message] .='<br> پاسخی دریافت نشد';break;
				case -12:$data[message] .='<br> نوع سیستم مدیریت محتوا تعیین نشده است';break;
				case -13:$data[message] .='<br> سیستم مدیریت محتوا اشتباه است';break;
			}
			$data[message]	.=	'<br /><a href="index.php" class="button">بازگشت</a>';
			$smarty->assign('data', $data );
			$smarty->display('message.tpl');
			exit;
		}
	}
	
	function url_decrypt($string){
		$counter = 0;
		$data = str_replace(array('-','_','.'),array('+','/','='),$string);
		$mod4 = strlen($data) % 4;
		if ($mod4) {
		$data .= substr('====', $mod4);
		}
		$decrypted = base64_decode($data);
		
		$check = array('tran_id','order_id','amount','refcode','status');
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
		$webgate_id = $data['webgate_id'];
		
		$resCode 	= $_POST['ResCode'];
		
		$irpul_token 	= $_GET['irpul_token'];
		$decrypted 		= url_decrypt( $irpul_token );
		if($decrypted['status']){
			parse_str($decrypted['data'], $ir_output);
			$tran_id 	= $ir_output['tran_id'];
			$order_id 	= $ir_output['order_id'];
			$amount 	= $ir_output['amount'];
			$refcode	= $ir_output['refcode'];
			$status 	= $ir_output['status'];
			
			if($status == 'paid')	
			{
				$payment 		= $db->fetch("SELECT * FROM payment WHERE payment_rand='$order_id' LIMIT 1;");
				$payment_id 	= $payment[payment_id];
				$payment_amount = $payment[payment_amount];
				$payment_status = $payment[payment_status];
				
				if ( $payment_status== 1){
					$parameters = array
					(
						'webgate_id'	=> $webgate_id,
						'tran_id' 		=> $tran_id,
						'amount'	 	=> $payment_amount,
					);
					
					try{
						$client = new SoapClient('https://irpul.ir/webservice.php?wsdl' , array('soap_version'=>'SOAP_1_2','encoding'=>'UTF-8'));
						$result = $client->PaymentVerification($parameters);
					}catch (Exception $e) { echo 'Error'. $e->getMessage();  }
				
					if( isset($result) && $result==1 ){
						//-- آماده کردن خروجی
						$output[status]		= 1;
						$output[res_num]	= $result;
						$output[ref_num]	= $refcode;
						$output[payment_id]	= $payment_id;
					}
					else
					{
						$output[status]	= 0;
						$output[message]= 'خطا در پرداخت. کد خطا: ' . $result;
					}
					
				}
				else
				{
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
