<?php

/**
* Project name: ZanborPanel
* Channel: @ZanborPanel
* Group: @ZanborPanelGap
 * Version: 1.0.0
**/

include_once 'config.php';

if(isJoin($from_id) == false){
    joinSend($from_id);
}

elseif($user['status'] == 'inactive' and $from_id != $config['dev']){
    sendMessage($from_id, $texts['block']);
}

elseif ($text == '/start' or $text == '🔙 بازگشت' or $text == '/back') {
    step('none');
    sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
}

elseif ($text == '❌  انصراف' and $user['step'] == 'confirm_service') {
    step('none');
    foreach ([$from_id . '-location.txt', $from_id . '-protocol.txt'] as $file) if (file_exists($file)) unlink($file);
	if($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0) $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
	sendMessage($from_id, sprintf($texts['start'], $first_name), $start_key);
}

elseif ($text == '🛒 خرید سرویس') {
	$servers = $sql->query("SELECT * FROM `panels` WHERE `status` = 'active'");
	if ($servers->num_rows > 0) {
		step('buy_service');
		if ($sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->num_rows > 0) $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
	    while ($row = $servers->fetch_assoc()) {
			$location[] = ['text' => $row['name']];
		}
		$location = array_chunk($location, 2);
	    $location[] = [['text' => '🔙 بازگشت']];
		$location = json_encode(['keyboard' => $location, 'resize_keyboard' => true]);
		sendMessage($from_id, $texts['select_location'], $location);
	} else {
	    sendmessage($from_id, $texts['inactive_buy_service'], $start_key);
	}
}

elseif ($user['step'] == 'buy_service') {
	$response = $sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'");
	if ($response->num_rows == 0) {
	    step('none');
	    sendMessage($from_id, $texts['choice_error']);
	} else {
    	step('select_plan');
        $plans = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'");
        while ($row = $plans->fetch_assoc()) {
            $plan[] = ['text' => $row['name']];
        }
        $plan = array_chunk($plan, 2);
    	$plan[] = [['text' => '🔙 بازگشت']];
    	$plan = json_encode(['keyboard' => $plan, 'resize_keyboard' => true]);
    	file_put_contents("$from_id-location.txt", $text);
    	sendMessage($from_id, $texts['select_plan'], $plan);
	}
}

elseif ($user['step'] == 'select_plan') {
	$response = $sql->query("SELECT `name` FROM `category` WHERE `name` = '$text'")->num_rows;
	if ($response > 0) {
    	step('confirm_service');
    	sendMessage($from_id, $texts['create_factor']);
    	$location = file_get_contents("$from_id-location.txt");
    	$plan = $text;
    	$code = rand(1111111, 9999999);
    	
    	$fetch = $sql->query("SELECT * FROM `category` WHERE `name` = '$text'")->fetch_assoc();
    	$price = $fetch['price'] ?? 0;
    	$limit = $fetch['limit'] ?? 0;
    	$date = $fetch['date'] ?? 0;
    	
    	$sql->query("INSERT INTO `service_factors` (`from_id`, `location`, `protocol`, `plan`, `price`, `code`, `status`) VALUES ('$from_id', '$location', 'null', '$plan', '$price', '$code', 'active')");
    	$confirm_service = json_encode(['keyboard' => [[['text' => '☑️ ایجاد سرویس']], [['text' => '❌  انصراف']]], 'resize_keyboard' => true]);
    	sendMessage($from_id, sprintf($texts['service_factor'], $location, $limit, $date, $code, number_format($price)), $confirm_service);
	} else {
	    sendMessage($from_id, $texts['choice_error']);
	}
}

elseif($user['step'] == 'confirm_service' and $text == '☑️ ایجاد سرویس'){
    step('none');
    sendMessage($from_id, $texts['create_service_proccess']);
    # ---------------- delete extra files ---------------- #
    foreach ([$from_id . '-location.txt', $from_id . '-protocol.txt'] as $file) if (file_exists($file)) unlink($file);
    # ---------------- get all information for create service ---------------- #
    $select_service = $sql->query("SELECT * FROM `service_factors` WHERE `from_id` = '$from_id'")->fetch_assoc();
    $location = $select_service['location'];
    $plan = $select_service['plan'];
    $price = $select_service['price'];
    $code = $select_service['code'];
    $status = $select_service['status'];
    $name = base64_encode($code) . '_' . $from_id;
    $get_plan = $sql->query("SELECT * FROM `category` WHERE `name` = '$plan'");
    $get_plan_fetch = $get_plan->fetch_assoc();
    $date = $get_plan_fetch['date'] ?? 0;
    $limit = $get_plan_fetch['limit'] ?? 0;
    $info_panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '$location'");
    # ---------------- check coin for create service ---------------- #
    if ($user['coin'] < $select_service['price']) {
        sendMessage($from_id, sprintf($texts['not_coin'], number_format($price)), $start_key);
        exit();
    }
    # ---------------- check database ----------------#
    if ($get_plan->num_rows == 0) {
        sendmessage($from_id, sprintf($texts['create_error'], 0), $start_key);
        exit();
    }
    # ---------------- set proxies proccess ---------------- #
    $get_panel = $info_panel->fetch_assoc();
    $protocols = explode('|', $get_panel['protocols']);
    unset($protocols[count($protocols)-1]);
    $proxies = array();
    foreach ($protocols as $protocol) {
    	if ($protocol == 'vless' and $get_panel['flow'] == 'flowon'){
            $proxies[$protocol] = array('flow' => 'xtls-rprx-vision');
        } else {
        	$proxies[$protocol] = array();
        }
    }
    # ---------------- create service proccess ---------------- #
    $create_service = createService($name, $limit, $date, $proxies, $get_panel['token'], $get_panel['login_link']);
    $create_status = json_decode($create_service, true);
    # ---------------- check errors ---------------- #
    if (!isset($create_status['username']) or $create_service == false) {
        sendMessage($from_id, sprintf($texts['create_error'], 1), $start_key);
        exit();
    }
    # ---------------- get links and subscription_url for send the user ---------------- #
    $links = "";
    foreach ($create_status['links'] as $link) $links .= $link . "\n\n";
    
    $sql->query("DELETE FROM `service_factors` WHERE `from_id` = '$from_id'");
    $sql->query("UPDATE `users` SET `coin` = coin - $price, `count_service` = count_service + 1 WHERE `from_id` = '$from_id' LIMIT 1");
    $sql->query("INSERT INTO `orders` (`from_id`, `location`, `protocol`, `date`, `volume`, `link`, `price`, `code`, `status`) VALUES ('$from_id', '$location', 'null', '$date', '$limit', '$links', '$price', '$code', 'active')");
    
    if ($info_panel->num_rows > 0) {
        $panel = $info_panel->fetch_assoc();
        if ($panel['qr_code'] == 'active') {
            $encode_url = urlencode($links);
            bot('sendPhoto', ['chat_id' => $from_id, 'photo' => "https://api.qrserver.com/v1/create-qr-code/?data=$encode_url&size=800x800", 'caption' => sprintf($texts['success_create_service'], $name, $location, $protocol, $date, $limit, $price, $links, $config['bot_username']), 'parse_mode' => 'html', 'reply_markup' => $start_key]);
        } else {
            sendmessage($from_id, sprintf($texts['success_create_service'], $name, $location, $protocol, $date, $limit, $price, $links, $config['bot_username']), $start_key);
        }
        sendmessage($config['dev'], sprintf($texts['success_create_notif']), $first_name, $username, $from_id, $user['count_service'], $user['coin'], $location, $plan, $limit, $date, $code, number_format($price));
    }else{
        sendmessage($from_id, sprintf($texts['create_error'], 2), $start_key);
    }
}

elseif ($text == '🛍 سرویس های من' or $data == 'back_services') {
    $services = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id'");
    if ($services->num_rows > 0) {
        while ($row = $services->fetch_assoc()) {
            $status = ($row['status'] == 'active') ? '🟢 | ' : '🔴 | ';
            $key[] = ['text' => $status . base64_encode($row['code']) . ' - ' . $row['location'], 'callback_data' => 'service_status-'.$row['code']];
        }
        $key = array_chunk($key, 2);
        $key = json_encode(['inline_keyboard' => $key]);
        if (isset($text)) {
            sendMessage($from_id, $texts['my_services'], $key);
        } else {
        	editMessage($from_id, $texts['my_services'], $message_id, $key);
        }
    } else {
    	if (isset($text)) {
            sendMessage($from_id, $texts['my_services_not_found'], $start_key);
        } else {
        	editMessage($from_id, $texts['my_services_not_found'], $message_id, $start_key);
        }
    }
}

elseif (strpos($data, 'service_status-') !== false) {
	alert($texts['wait_second'], false);
	$code = explode('-', $data)[1];
	$getService = $sql->query("SELECT * FROM `orders` WHERE `code` = '$code'")->fetch_assoc();
	$panel = $sql->query("SELECT * FROM `panels` WHERE `name` = '{$getService['location']}'")->fetch_assoc();
	$getUser = getUserInfo(base64_encode($code) . '_' . $from_id, $panel['token'], $panel['login_link']);
	$links = implode("\n\n", $getUser['links']) ?? 'NULL';
	editMessage($from_id, sprintf($texts['your_service'], ($getUser['status'] == 'active') ? '🟢 فعال' : '🔴 غیرفعال', $getService['location'], base64_encode($code), Conversion($getUser['used_traffic'], 'GB'), Conversion($getUser['data_limit'], 'GB'), date('Y-d-m H:i:s',  $getUser['expire']), $links), $message_id, $back_services);
}

elseif ($text == '💸 شارژ حساب') {
    step('diposet');
    sendMessage($from_id, $texts['diposet'], $back);
}

elseif ($user['step'] == 'diposet') {
    if (is_numeric($text) and $text >= 2000) {
        step('sdp-' . $text);
        sendMessage($from_id, sprintf($texts['select_diposet_payment'], number_format($text)), $select_diposet_payment);
    } else {
        sendMessage($from_id, $texts['diposet_input_invalid'], $back);
    }
}

elseif ($data == 'cancel_payment_proccess') {
    step('none');
    deleteMessage($from_id, $message_id);
    sendMessage($from_id, $texts['start'], $start_key);
}

elseif (in_array($data, ['zarinpal', 'idpay']) and strpos($user['step'], 'sdp-') !== false) {
    $status = $sql->query("SELECT `{$data}_token` FROM `payment_setting`")->fetch_assoc()[$data . '_token'];
    if ($status != 'none') {
        step('none');
        $price = explode('-', $user['step'])[1];
        $code = rand(11111111, 99999999);
        $sql->query("INSERT INTO `factors` (`from_id`, `price`, `code`, `status`) VALUES ('$from_id', '$price', '$code', 'no')");
        $response = ($data == 'zarinpal') ? zarinpalGenerator($from_id, $price, $code) : idpayGenerator($from_id, $price, $code);
        if ($response) $pay = json_encode(['inline_keyboard' => [[['text' => '💵 پرداخت', 'url' => $response]]]]);
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, sprintf($texts['create_diposet_factor'], $code, number_format($price)), $pay);
        sendMessage($from_id, $texts['back_to_menu'], $start_key);
    } else {
        alert($texts['error_choice_pay']);
    }
}

elseif ($data == 'kart') {
    $price = explode('-', $user['step'])[1];
    step('send_fish-'.$price);
    $code = rand(11111111, 99999999);
    $card_number = $sql->query("SELECT `card_number` FROM `payment_setting`")->fetch_assoc()['card_number'];
    $card_number_name = $sql->query("SELECT `card_number_name` FROM `payment_setting`")->fetch_assoc()['card_number_name'];
    deleteMessage($from_id, $message_id);
    sendMessage($from_id, sprintf($texts['create_kart_factor'], $code, number_format($price), ($card_number != 'none') ? $card_number : 'NotSet', ($card_number_name != 'none') ? $card_number_name : ''), $back);
}

elseif (strpos($user['step'], 'send_fish') !== false) {
    $price = explode('-', $user['step'])[1];
    if (isset($update->message->photo)) {
        step('none');
        $key = json_encode(['inline_keyboard' => [[['text' => '❌', 'callback_data' => 'cancel_fish-'.$from_id], ['text' => '✅', 'callback_data' => 'accept_fish-'.$from_id.'-'.$price]]]]);
        sendMessage($from_id, $texts['success_send_fish'], $start_key);
        sendMessage($config['dev'], sprintf($texts['success_send_fish_notif'], $from_id, $username, $price), $key);
        forwardMessage($from_id, $config['dev'], $message_id);
    } else {
        sendMessage($from_id, $texts['error_input_kart'], $back);
    }
}

elseif ($text == '🛒 تعرفه خدمات') {
    sendMessage($from_id, $texts['service_tariff']);
}

elseif ($text == '👤 پروفایل') {
    $count_all = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id'")->num_rows;
    $count_all_active = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id' AND `status` = 'active'")->num_rows;
    $count_all_inactive = $sql->query("SELECT * FROM `orders` WHERE `from_id` = '$from_id' AND `status` = 'inactive'")->num_rows;
    sendMessage($from_id, sprintf($texts['my_account'], $from_id, number_format($user['coin']), $count_all, $count_all_active, $count_all_inactive), $start_key);
}

elseif ($text == '📮 پشتیبانی آنلاین') {
    step('support');
    sendMessage($from_id, $texts['support'], $back);
}

elseif ($user['step'] == 'support') {
    step('none');
    sendMessage($from_id, $texts['success_support'], $start_key);
    sendMessage($config['dev'], sprintf($texts['new_support_message'], $from_id, $from_id, $username, $user['coin']), $manage_user);
    forwardMessage($from_id, $config['dev'], $message_id);
}

# ------------ panel ------------ #

if ($from_id == $config['dev'] or in_array($from_id, $sql->query("SELECT * FROM `admins`")->fetch_assoc() ?? [])) {
    if (in_array($text, ['/panel', 'panel', '🔧 مدیریت', 'پنل', '⬅️ بازگشت به مدیریت'])) {
        step('panel');
        sendMessage($from_id, "👮‍♂️ - سلام ادمین [ <b>$first_name</b> ] عزیز !\n\n⚡️به پنل مدیریت ربات خوش آمدید.\n🗃 ورژن فعلی ربات : <code>{$config['version']}</code>\n\n⚙️ جهت مدیریت ربات ، یکی از گزینه های زیر را انتخاب کنید.\n\n🐝 | برای اطلاع از تمامی آپدیت ها و نسخه های بعدی ربات زنبور پنل در کانال زنبور پنل عضو شید :↓\n◽️@ZanborPanel\n🐝 و همچنین برای نظر دهی آپدیت یا باگ ها به گروه زنبور پنل بپیوندید :↓\n◽️@ZanborPanelGap", $panel);    
    }
    
    elseif($text == '👥 مدیریت آمار ربات'){
        sendMessage($from_id, "👋 به مدیریت آمار کلی ربات خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید:\n\n◽️@ZanborPanel", $manage_statistics);
    }
    
    elseif($text == '🌐 مدیریت سرور'){
        sendMessage($from_id, "⚙️ به مدیریت پلن ها خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :\n\n◽️@ZanborPanel", $manage_server);
    }
    
    elseif($text == '👤 مدیریت کاربران'){
        sendMessage($from_id, "👤 به مدیریت کاربران خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :\n\n◽️@ZanborPanel", $manage_user);
    }
    
    elseif($text == '📤 مدیریت پیام'){
        sendMessage($from_id, "📤 به مدیریت پیام خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :\n\n◽️@ZanborPanel", $manage_message);
    }
    
    elseif($text == '👮‍♂️مدیریت ادمین'){
        sendMessage($from_id, "👮‍♂️ به مدیریت ادمین خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :\n\n◽️@ZanborPanel", $manage_admin);
    }
    
    elseif($text == '⚙️ تنظیمات'){
        sendMessage($from_id, "⚙️️ به تنظیمات ربات خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :\n\n◽️@ZanborPanel", $manage_setting);
    }
    
    // ----------- do not touch this part ----------- //
    elseif ($text == base64_decode('YmFzZTY0X2RlY29kZQ==')('8J+TniDYp9i32YTYp9i524zZhyDYotm+2K/bjNiqINix2KjYp9iq')) {
        base64_decode('c2VuZE1lc3NhZ2U=')($from_id, base64_decode('8J+QnSB8INio2LHYp9uMINin2LfZhNin2Lkg2KfYsiDYqtmF2KfZhduMINii2b7Yr9uM2Kog2YfYpyDZiCDZhtiz2K7ZhyDZh9in24wg2KjYudiv24wg2LHYqNin2Kog2LLZhtio2YjYsSDZvtmG2YQg2K/YsSDaqdin2YbYp9mEINiy2YbYqNmI2LEg2b7ZhtmEINi52LbZiCDYtNuM2K8gOuKGkwril73vuI9AWmFuYm9yUGFuZWwK8J+QnSB8INmIINmH2YXahtmG24zZhiDYqNix2KfbjCDZhti42LEg2K/Zh9uMINii2b7Yr9uM2Kog24zYpyDYqNin2q8g2YfYpyDYqNmHINqv2LHZiNmHINiy2YbYqNmI2LEg2b7ZhtmEINio2b7bjNmI2YbYr9uM2K8gOuKGkwril73vuI9AWmFuYm9yUGFuZWxHYXAK8J+QnSB8INmG2YXZiNmG2Ycg2LHYqNin2Kog2KLYrtix24zZhiDZhtiz2K7ZhyDYsdio2KfYqiDYstmG2KjZiNixINm+2YbZhCA64oaTCuKXve+4j0BaYW5ib3JQYW5lbEJvdA=='), $panel);
    }
    
    // ----------- manage status ----------- //
    elseif($text == '👤 آمار ربات'){
        $state1 = $sql->query("SELECT `status` FROM `users`")->num_rows;
        $state2 = $sql->query("SELECT `status` FROM `users` WHERE `status` = 'inactive'")->num_rows;
        $state3 = $sql->query("SELECT `status` FROM `users` WHERE `status` = 'active'")->num_rows;
        $state4 = $sql->query("SELECT `status` FROM `factors` WHERE `status` = 'yes'")->num_rows;
        sendMessage($from_id, "⚙️ آمار ربات شما به شرح زیر می‌باشد :↓\n\n▫️تعداد کل کاربر ربات : <code>$state1</code> عدد\n▫️تعداد کاربر های مسدود : <code>$state2</code> عدد\n▫️تعداد کاربر های آزاد : <code>$state3</code> عدد\n\n🔢 تعداد کل پرداختی : <code>$state4</code> عدد\n\n🤖 @ZanborPanel", $manage_statistics);
    }
    
    // ----------- manage servers ----------- //
    elseif ($text == '❌ انصراف و بازگشت') {
        step('none');
        if (file_exists('add_panel.txt')) unlink('add_panel.txt');
        sendMessage($from_id, "⚙️ به مدیریت پلن ها خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :\n\n◽️@ZanborPanel", $manage_server);
    }
    
    elseif ($data == 'close_panel') {
        editMessage($from_id, "✅ پنل مدیریت سرور ها با موفقیت بسته شد !", $message_id);
    }
    
    elseif  ($text == '➕ افزودن سرور') {
        step('add_server');
        sendMessage($from_id, "‌👈🏻⁩ اسم پنل خود را به دلخواه ارسال کنید :↓\n\nمثال نام : 🇳🇱 - هلند\n• این اسم برای کاربران قابل نمایش است.", $cancel_add_server);
    }
    
    elseif ($user['step'] == 'add_server') {
        if ($sql->query("SELECT `name` FROM `panels` WHERE `name` = '$text'")->num_rows == 0) {
            step('send_address');
            file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
            sendMessage($from_id, "🌐 آدرس لاگین به پنل را ارسال کنید.\n\n- example : http://1.1.1.1:8000", $cancel_add_server);
        } else {
            sendMessage($from_id, "❌ پنلی با نام [ <b>$text</b> ] قبلا در ربات ثبت شده !", $cancel_add_server);
        }
    }
    
    elseif ($user['step'] == 'send_address') {
        if (preg_match("/^(http|https):\/\/\d+\.\d+\.\d+\.\d+\:\d+$/", $text)) {
            if ($sql->query("SELECT `login_link` FROM `panels` WHERE `login_link` = '$text'")->num_rows == 0) {
                step('send_username');
                file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
                sendMessage($from_id, "🔎 - یوزرنیم ( <b>username</b> ) پنل خود را ارسال کنید :", $cancel_add_server);
            } else {
            sendMessage($from_id, "❌ پنلی با ادرس [ <b>$text</b> ] قبلا در ربات ثبت شده !", $cancel_add_server);
        }
        } else {
            sendMessage($from_id, "🚫 لینک ارسالی شما اشتباه است !", $cancel_add_server);
        }
    }
    
    elseif ($user['step'] == 'send_username') {
        step('send_password');
        file_put_contents('add_panel.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "🔎 - پسورد ( <b>password</b> ) سرور خود را ارسال کنید :", $cancel_add_server);
    }
    
    elseif ($user['step'] == 'send_password') {
        step('none');
        $info = explode("\n", file_get_contents('add_panel.txt'));
        $response = loginPanel($info[1], $info[2], $text);
        if (isset($response['access_token'])) {
            $code = rand(11111111, 99999999);
            $sql->query("INSERT INTO `panels` (`name`, `login_link`, `username`, `password`, `token`, `code`) VALUES ('{$info[0]}', '{$info[1]}', '{$info[2]}', '$text', '{$response['access_token']}', '$code')");
            sendMessage($from_id, "✅ ربات با موفقیت به پنل شما لاگین شد!\n\n▫️یوزرنیم : <code>{$info[2]}</code>\n▫️پسورد : <code>{$info[3]}</code>\n▫️کد پیگیری : <code>$code</code>", $manage_server);
        } else {
            sendMessage($from_id, "❌ لاگین به پنل با خطا مواجه شد , بعد از گذشت چند دقیقه مجددا تلاش کنید !\n\n🎯 دلایل ممکن متصل نشدن ربات به پنل شما :↓\n\n◽باز نبودن پورت مورد نظر\n◽باز نشدن آدرس ارسالی\n◽آدرس ارسالی اشتباه\n◽یوزرنیم یا پسورد اشتباه\n◽قرار گرفتن آی‌پی در بلاک لیست\n◽️باز نبودن دسترسی CURL\n◽️مشکل کلی هاست", $manage_server);
        }
        if (file_exists('add_panel.txt')) unlink('add_panel.txt');
    }
    
    elseif ($text == '🎟 افزودن پلن') {
        step('add_name');
        sendMessage($from_id, "👇🏻نام این دسته بندی را  ارسال کنید :↓", $back_panel);
    }
    
    elseif ($user['step'] == 'add_name' and $text != '⬅️ بازگشت به مدیریت') {
        step('add_limit');
        file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "👇🏻حجم خود را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>50</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_limit' and $text != '⬅️ بازگشت به مدیریت') {
        step('add_date');
        file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "👇🏻تاریخ خود را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>30</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_date' and $text != '⬅️ بازگشت به مدیریت') {
        step('add_price');
        file_put_contents('add_plan.txt', "$text\n", FILE_APPEND);
        sendMessage($from_id, "💸 مبلغ این حجم را به صورت عدد صحیح و لاتین ارسال کنید :↓\n\n◽نمونه : <code>60000</code>", $back_panel);
    }
    
    elseif ($user['step'] == 'add_price' and $text != '⬅️ بازگشت به مدیریت') {
        step('none');
        $info = explode("\n", file_get_contents('add_plan.txt'));
        $code = rand(1111111, 9999999);
        $sql->query("INSERT INTO `category` (`limit`, `date`, `name`, `price`, `code`, `status`) VALUES ('{$info[1]}', '{$info[2]}', '{$info[0]}', '$text', '$code', 'active')");
        sendmessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت و به لیست اضافه شد.\n\n◽حجم ارسالی : <code>{$info[1]}</code>\n◽قیمت ارسالی : <code>$text</code>", $manage_server);
        if (file_exists('add_plan.txt')) unlink('add_plan.txt');
    }
    
    elseif ($text == '⚙️ لیست سرور ها' or $data == 'back_panellist') {
        step('none');
        $info_servers = $sql->query("SELECT * FROM `panels`");
        if($info_servers->num_rows == 0){
            if(!isset($data)){
                sendMessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.");
            }else{
                editءessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.", $message_id);
            }
            exit();
        }
        $key[] = [['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️نام', 'callback_data' => 'null'], ['text' => '▫️کد پیگیری', 'callback_data' => 'null']];
        while($row = $info_servers->fetch_array()){
            $name = $row['name'];
            $code = $row['code'];
            if($row['status'] == 'active') $status = '✅ فعال'; else $status = '❌ غیرفعال';
            $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-'.$code], ['text' => $name, 'callback_data' => 'status_panel-'.$code], ['text' => $code, 'callback_data' => 'status_panel-'.$code]];
        }
        $key[] = [['text' => '❌ بستن پنل | close panel', 'callback_data' => 'close_panel']];
        $key = json_encode(['inline_keyboard' => $key]);
        if(!isset($data)){
            sendMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $key);
        }else{
            editMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $message_id, $key);
        }
    }
    
    elseif (strpos($data, 'change_status_panel-') !== false) {
        $code = explode('-', $data)[1];
        $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'");
        $status = $info_panel->fetch_assoc()['status'];
        if($status == 'active'){
            $sql->query("UPDATE `panels` SET `status` = 'inactive' WHERE `code` = '$code'");
        }else{
            $sql->query("UPDATE `panels` SET `status` = 'active' WHERE `code` = '$code'");
        }
        $key[] = [['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️نام', 'callback_data' => 'null'], ['text' => '▫️کد پیگیری', 'callback_data' => 'null']];
        $result = $sql->query("SELECT * FROM `panels`");
        while($row = $result->fetch_array()){
            $name = $row['name'];
            $code = $row['code'];
            if($row['status'] == 'active') $status = '✅ فعال'; else $status = '❌ غیرفعال';
            $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-'.$code], ['text' => $name, 'callback_data' => 'status_panel-'.$code], ['text' => $code, 'callback_data' => 'status_panel-'.$code]];
        }
        $key[] = [['text' => '❌ بستن پنل | close panel', 'callback_data' => 'close_panel']];
        $key = json_encode(['inline_keyboard' => $key]);
        editMessage($from_id, "🔎 لیست سرور های ثبت شما :\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $message_id, $key);
    }
    
    elseif (strpos($data, 'status_panel-') !== false or strpos($data, 'update_panel-') !== false) {
    	alert('🔄 - لطفا چند ثانیه صبر کنید در حال دریافت اطلاعات . . .', false);
    
        $code = explode('-', $data)[1];
        $info_server = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'")->fetch_assoc();
        
        if ($info_server['status'] == 'active') $status = '✅ فعال'; else $status = '❌ غیرفعال';
        if (strpos($info_server['login_link'], 'https://') !== false) $status_ssl = '✅ فعال'; else $status_ssl = '❌ غیرفعال';
        
        $info = [
            'ip' => explode(':', str_replace(['http://', 'https://'], '', $info_server['login_link']))[0] ?? '⚠️',
            'port' => explode(':', str_replace(['http://', 'https://'], '', $info_server['login_link']))[1] ?? '⚠️',
        ];
        
        $txt = "اطلاعات پنل [ <b>{$info_server['name']}</b> ] با موفقیت دریافت شد.\n\n🔎 وضعیت فعلی در ربات : <b>$status</b>\nℹ️ کد سرور ( برای اطلاعات ) : <code>$code</code>\n\n◽️لوکیشن : <b>{$info_server['name']}</b>\n◽️آیپی : <code>{$info['ip']}</code>\n◽️پورت : <code>{$info['port']}</code>\n◽️وضعیت ssl : <b>$status_ssl</b>\n\n🔑 یوزرنیم پنل : <code>{$info_server['username']}</code>\n🔑 پسورد پنل : <code>{$info_server['password']}</code>";
        
        $protocols = explode('|', $info_server['protocols']);
        unset($protocols[count($protocols)-1]);
        if (in_array('vmess', $protocols)) $vmess_status = '✅'; else $vmess_status = '❌';
        if (in_array('trojan', $protocols)) $trojan_status = '✅'; else $trojan_status = '❌';
        if (in_array('vless', $protocols)) $vless_status = '✅'; else $vless_status = '❌';
        if (in_array('shadowsocks', $protocols)) $shadowsocks_status = '✅'; else $shadowsocks_status = '❌';
        
        $back_panellist = json_encode(['inline_keyboard' => [
            [['text' => '🆙 آپدیت اطلاعات', 'callback_data' => 'update_panel-' . $code]],
            [['text' => '🔎 - Status :', 'callback_data' => 'null'], ['text' => $info_server['status'] == 'active' ? '✅' : '❌', 'callback_data' => 'change_status_panel-' . $code]],
            [['text' => '🎯 - Flow :', 'callback_data' => 'null'], ['text' => $info_server['flow'] == 'flowon' ? '✅' : '❌', 'callback_data' => 'change_status_flow-' . $code]],
            [['text' => '🗑 حذف پنل', 'callback_data' => 'delete_panel-' . $code], ['text' => '✍️ تغییر نام', 'callback_data' => 'change_name_panel-' . $code]],
            [['text' => 'vmess - [' . $vmess_status . ']', 'callback_data' => 'change_protocol|vmess-' . $code], ['text' => 'trojan [' . $trojan_status . ']', 'callback_data' => 'change_protocol|trojan-' . $code], ['text' => 'vless [' . $vless_status . ']', 'callback_data' => 'change_protocol|vless-' . $code]],
            [['text' => 'shadowsocks [' . $shadowsocks_status . ']', 'callback_data' => 'change_protocol|shadowsocks-' . $code]],
            [['text' => '🔙 بازگشت به لیست پنل ها', 'callback_data' => 'back_panellist']],
        ]]);
        editMessage($from_id, $txt, $message_id, $back_panellist);
    }
    
    elseif (strpos($data, 'change_status_flow-') !== false) {
    	$code = explode('-', $data)[1];
        $info_panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code'");
        $status = $info_panel->fetch_assoc()['flow'];
        if($status == 'flowon'){
            $sql->query("UPDATE `panels` SET `flow` = 'flowoff' WHERE `code` = '$code'");
        }else{
            $sql->query("UPDATE `panels` SET `flow` = 'flowon' WHERE `code` = '$code'");
        }
        $back = json_encode(['inline_keyboard' => [[['text' => '🆙 آپدیت اطلاعات', 'callback_data' => 'update_panel-'.$code]]]]);
        editmessage($from_id, '✅ تغییرات با موفقیت انجام شد.', $message_id, $back);
    }
    
    elseif (strpos($data, 'change_protocol|') !== false) {
        $code = explode('-', $data)[1];
        $protocol = explode('-', explode('|', $data)[1])[0];
        $panel = $sql->query("SELECT * FROM `panels` WHERE `code` = '$code' LIMIT 1")->fetch_assoc();
        $protocols = explode('|', $panel['protocols']);
        unset($protocols[count($protocols)-1]);
        
        if($protocol == 'vless'){
            if(in_array($protocol, $protocols)){
                unset($protocols[array_search($protocol, $protocols)]);
            }else{
                array_push($protocols, $protocol);
            }
        }elseif($protocol == 'vmess'){
            if(in_array($protocol, $protocols)){
                unset($protocols[array_search($protocol, $protocols)]);
            }else{
                array_push($protocols, $protocol);
            }
        }elseif($protocol == 'trojan'){
            if(in_array($protocol, $protocols)){
                unset($protocols[array_search($protocol, $protocols)]);
            }else{
                array_push($protocols, $protocol);
            }
        }elseif($protocol == 'shadowsocks'){
            if(in_array($protocol, $protocols)){
                unset($protocols[array_search($protocol, $protocols)]);
            }else{
                array_push($protocols, $protocol);
            }
        }
        
        $protocols = join('|', $protocols) . '|';
        $sql->query("UPDATE `panels` SET `protocols` = '$protocols' WHERE `code` = '$code' LIMIT 1");
        
        $back = json_encode(['inline_keyboard' => [[['text' => '🆙 آپدیت اطلاعات', 'callback_data' => 'update_panel-'.$code]]]]);
        editmessage($from_id, '✅ تغییر وضعیت پروتکل با موفقیت انجام شد.', $message_id, $back);
        
    }
    
    elseif (strpos($data, 'change_name_panel-') !== false) {
        $code = explode('-', $data)[1];
        step('change_name-'.$code);
        sendMessage($from_id, "🔰نام جدید پنل را ارسال کنید :", $back_panel);
    }
    
    elseif (strpos($user['step'], 'change_name-') !== false) {
        $code = explode('-', $user['step'])[1];
        step('none', $from_id);
        $sql->query("UPDATE `panels` SET `name` = '$text' WHERE `code` = '$code'");
        sendMessage($from_id, "✅ نام پنل با موفقیت بر روی [ <b>$text</b> ] تنظیم شد.", $back_panellist);
    }
    
    elseif (strpos($data, 'delete_panel-') !== false) {
        step('none');
        $code = explode('-', $data)[1];
        $sql->query("DELETE FROM `panels` WHERE `code` = '$code'");
        $info_servers = $sql->query("SELECT * FROM `panels`");
        if($info_servers->num_rows == 0){
            if(!isset($data)){
                sendMessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.");
            }else{
                editMessage($from_id, "❌ هیچ سروری در ربات ثبت نشده است.", $message_id);
            }
            exit();
        }
        $key[] = [['text' => '▫️وضعیت', 'callback_data' => 'null'], ['text' => '▫️نام', 'callback_data' => 'null'], ['text' => '▫️کد پیگیری', 'callback_data' => 'null']];
        while($row = $info_servers->fetch_array()){
            $name = $row['name'];
            $code = $row['code'];
            if($row['status'] == 'active') $status = '✅ فعال'; else $status = '❌ غیرفعال';
            $key[] = [['text' => $status, 'callback_data' => 'change_status_panel-'.$code], ['text' => $name, 'callback_data' => 'status_panel-'.$code], ['text' => $code, 'callback_data' => 'status_panel-'.$code]];
        }
        $key[] = [['text' => '❌ بستن پنل | close panel', 'callback_data' => 'close_panel']];
        $key = json_encode(['inline_keyboard' => $key]);
        if(!isset($data)){
            sendMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $key);
        }else{
            editMessage($from_id, "🔎 لیست سرور های ثبت شده شما :\n\nℹ️ برای مدیریت هر کدام بر روی آن کلیک کنید.", $message_id, $key);
        }
    }
    
    elseif ($text == '⚙️ مدیریت پلن ها' or $data == 'back_cat') {
        step('manage_limit');
        $count = $sql->query("SELECT * FROM `category`")->num_rows;
        if ($count == 0) {
            if(isset($data)){
                editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
                exit();
            } else {
                sendmessage($from_id, "❌ لیست پلن ها خالی است.", $manage_server);
                exit();
            }
        }
        $result = $sql->query("SELECT * FROM `category`");
        $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
        while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
        }else{
            sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
        }
    }
    
    elseif (strpos($data, 'change_status_cat-') !== false) {
        $code = explode('-', $data)[1];
        $info_cat = $sql->query("SELECT * FROM `category` WHERE `code` = '$code' LIMIT 1");
        $status = $info_cat->fetch_assoc()['status'];
        if ($status == 'active') {
            $sql->query("UPDATE `category` SET `status` = 'inactive' WHERE `code` = '$code'");
        } else {
            $sql->query("UPDATE `category` SET `status` = 'active' WHERE `code` = '$code'");
        }
        $button[] = [['text' => 'حذف', 'callback_data' => 'null'], ['text' => 'وضعیت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null'], ['text' => 'اطلاعات', 'callback_data' => 'null']];
        $result = $sql->query("SELECT * FROM `category`");
       while ($row = $result->fetch_array()) {
            $status = $row['status'] == 'active' ? '✅' : '❌';
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-'.$row['code']], ['text' => $status, 'callback_data' => 'change_status_cat-'.$row['code']], ['text' => $row['name'], 'callback_data' => 'manage_list-'.$row['code']], ['text' => '👁', 'callback_data' => 'manage_cat-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        $count_active = $sql->query("SELECT * FROM `category` WHERE `status` = 'active'")->num_rows;
        if (isset($data)) {
            editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
        }else{
            sendMessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $button);
        }
    }
    
    elseif (strpos($data, 'delete_limit-') !== false) {
        $code = explode('-', $data)[1];
        $sql->query("DELETE FROM `category` WHERE `code` = '$code' LIMIT 1");
        $count = $sql->query("SELECT * FROM `category`")->num_rows;
        if ($count == 0) {
            editmessage($from_id, "❌ لیست پلن ها خالی است.", $message_id);
            exit();
        }
        $result = $sql->query("SELECT * FROM `category`");
        while ($row = $result->fetch_array()) {
            $button[] = [['text' => '🗑', 'callback_data' => 'delete_limit-'.$code], ['text' => $row['name'], 'callback_data' => 'manage_list-'.$row['code']]];
        }
        $button = json_encode(['inline_keyboard' => $button]);
        $count = $result->num_rows;
        editmessage($from_id, "🔰لیست دسته بندی های شما به شرح زیر است :\n\n🔢 تعداد کل : <code>$count</code> عدد\n🔢 تعداد کل لیست فعال : <code>$count_active</code>  عدد", $message_id, $button);
    }
    
    elseif (strpos($data, 'manage_list-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category` WHERE `code` = '$code'")->fetch_assoc();
        alert($res['name']);
    }
    
    elseif (strpos($data, 'manage_cat-') !== false) {
        $code = explode('-', $data)[1];
        $res = $sql->query("SELECT * FROM `category` WHERE `code` = '$code'")->fetch_assoc();
        $key = json_encode(['inline_keyboard' => [
            [['text' => 'تاریخ', 'callback_data' => 'null'], ['text' => 'حجم', 'callback_data' => 'null'], ['text' => 'قیمت', 'callback_data' => 'null'], ['text' => 'نام', 'callback_data' => 'null']],
            [['text' => $res['date'], 'callback_data' => 'change_date-'.$res['code']], ['text' => $res['limit'], 'callback_data' => 'change_limit-'.$res['code']], ['text' => $res['price'], 'callback_data' => 'change_price-'.$res['code']], ['text' => '✏️', 'callback_data' => 'change_name-'.$res['code']]],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'back_cat']],
        ]]);
        editmessage($from_id, "🌐 اطلاعات پلن با موفقیت دریافت شد.\n\n▫️نام پلن : <b>{$res['name']}</b>\n▫️حجم : <code>{$res['limit']}</code>\n▫️تاریخ : <code>{$res['date']}</code>\n▫️قیمت : <code>{$res['price']}</code>\n\n📎 با کلیک بر روی هر کدام میتوانید مقدار آن را تغییر دهید !", $message_id, $key);
    }
    
    elseif (strpos($data, 'change_date-') !== false) {
        $code = explode('-', $data)[1];
        step('change_date-'.$code);
        sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
    }
    
    elseif (strpos($data, 'change_limit-') !== false) {
        $code = explode('-', $data)[1];
        step('change_limit-'.$code);
        sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
    }
    
    elseif (strpos($data, 'change_price-') !== false) {
        $code = explode('-', $data)[1];
        step('change_price-'.$code);
        sendMessage($from_id, "🔰مقدار جدید را به صورت عدد صحیح و لاتین ارسال کنید :", $back_panel);
    }
    
    elseif (strpos($data, 'change_name-') !== false) {
        $code = explode('-', $data)[1];
        step('change_namee-'.$code);
        sendMessage($from_id, "🔰نام جدید را ارسال کنید :", $back_panel);
    }
    
    elseif (strpos($user['step'], 'change_date-') !== false and $text != '⬅️ بازگشت به مدیریت') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `date` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_limit-') !== false and $text != '⬅️ بازگشت به مدیریت') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `limit` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_price-') !== false and $text != '⬅️ بازگشت به مدیریت') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `price` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
    }
    
    elseif (strpos($user['step'], 'change_namee-') !== false and $text != '⬅️ بازگشت به مدیریت') {
        $code = explode('-', $user['step'])[1];
        step('none');
        $sql->query("UPDATE `category` SET `name` = '$text' WHERE `code` = '$code' LIMIT 1");
        sendMessage($from_id, "✅ اطلاعات ارسالی شما با موفقیت ثبت شد.", $manage_server);
    }
    
    // ----------- manage message ----------- //
    elseif($text == '🔎 وضعیت ارسال / فوروارد همگانی'){
        $info_send = $sql->query("SELECT * FROM `sends`")->fetch_assoc();
        if($info_send['send'] == 'yes') $send_status = '✅'; else $send_status = '❌';
        if($info_send['step'] == 'send') $status_send = '✅'; else $status_send = '❌';
        if($info_send['step'] == 'forward') $status_forward = '✅'; else $status_forward = '❌';
        sendMessage($from_id, "👇🏻وضعیت ارسال های شما به شرح زیر است :\n\nℹ️ در صف ارسال/فوروارد : <b>$send_status</b>\n⬅️ ارسال همگانی : <b>$status_send</b>\n⬅️ فوروارد همگانی : <b>$status_forward</b>\n\n🟥 برای لغو ارسال/فوروارد همگانی دستور /cancel_send را ارسال کنید.", $manage_message);
    }
    
    elseif($text == '/cancel_send'){
        $sql->query("UPDATE `sends` SET `send` = 'no', `text` = 'null', `type` = 'null', `step` = 'null'");
        sendMessage($from_id, "✅ ارسال/فوروارد همگانی شما با موفقیت لغو شد.", $manage_message);   
    }
    
    elseif($text == '📬 ارسال همگانی'){
        step('send_all');
        sendMessage($from_id, "👇 متن خود را در قالب یک پیام ارسال کنید :", $back_panel);
    }
    
    elseif($user['step'] == 'send_all'){
        step('none');
        if (isset($update->message->text)){
            $type = 'text';
        }else{
            $type = $update->message->photo[count($update->message->photo)-1]->file_id;
            $text = $update->message->caption;
        }
        $sql->query("UPDATE `sends` SET `send` = 'yes', `text` = '$text', `type` = '$type', `step` = 'send'");
        sendMessage($from_id, "✅ پیام شما با موفقیت به صف ارسال همگانی اضافه شد !", $manage_message);
    }
    
    elseif($text == '📬 فوروارد همگانی'){
        step('for_all');
        sendMessage($from_id, "‌‌👈🏻⁩ متن خود را فوروارد کنید :", $back_panel);
    }
    
    elseif($user['step'] == 'for_all'){
        step('none');
        sendMessage($from_id, "✅ پیام شما با موفقیت به صف فوروارد همگانی اضافه شد !", $panel);
        $sql->query("UPDATE `sends` SET `send` = 'yes', `text` = '$message_id', `type` = '$from_id', `step` = 'forward'");
    }
    
    elseif($text == '📞 ارسال پیام به کاربر' or $text == '📤 ارسال پیام به کاربر'){
        step('sendmessage_user1');
        sendMessage($from_id, "🔢 ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif($user['step'] == 'sendmessage_user1' and $text != '⬅️ بازگشت به مدیریت'){
        if ($sql->query("SELECT `from_id` FROM `users` WHERE `from_id` = '$text'")->num_rows > 0) {
            step('sendmessage_user2');
            file_put_contents('id.txt', $text);
            sendMessage($from_id, "👇 پیام خود را در قالب یک متن ارسال کنید :", $back_panel);
        } else {
            step('sendmessage_user1');
            sendMessage($from_id, "❌ آیدی عددی ارسالی شما عضو ربات نیست !", $back_panel);
        }
    }
    
    elseif ($user['step'] == 'sendmessage_user2' and $text != '⬅️ بازگشت به مدیریت') {
        step('none');
        $id = file_get_contents('id.txt');
        sendMessage($from_id, "✅ پیام شما با موفقیت به کاربر <code>$id</code> ارسال شد.", $manage_message);
        if (isset($update->message->text)){
            sendmessage($id, $text);
        } else {
            $file_id = $update->message->photo[count($update->message->photo)-1]->file_id;
            $caption = $update->message->caption;
            bot('sendphoto', ['chat_id' => $id, 'photo' => $file_id, 'caption' => $caption]);
        }
        unlink('id.txt');
    }
    
    // ----------- manage users ----------- //
    elseif ($text == '🔎 اطلاعات کاربر') {
        step('info_user');
        sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'info_user') {
        $info = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
        if ($info->num_rows > 0) {
            step('none');
            $res_get = bot('getchatmember', ['user_id' => $text, 'chat_id' => $text]);
            $first_name = $res_get->result->user->first_name;
            $username = '@' . $res_get->result->user->username;
            $coin = number_format($info->fetch_assoc()['coin']) ?? 0;
            $count_service = $info->fetch_assoc()['count_service'] ?? 0;
            $count_payment = $info->fetch_assoc()['count_charge'] ?? 0;   
            sendMessage($from_id, "⭕️ اطلاعات کاربر [ <code>$text</code> ] با موفقیت دریافت شد.\n\n▫️یوزرنیم کاربر : $username\n▫️نام کاربر : <b>$first_name</b>\n▫️موجودی کاربر : <code>$coin</code> تومان\n▫️ تعدادی سرویس کاربر : <code>$count_service</code> عدد\n▫️تعداد پرداختی کاربر : <code>$count_payment</code> عدد", $manage_user);
        } else {
            sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
        }
    }
    
    elseif ($text == '➕ افزایش موجودی') {
        step('add_coin');
        sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'add_coin') {
        $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
        if($user->num_rows > 0){
            step('add_coin2');
            file_put_contents('id.txt', $text);
            sendMessage($from_id, "🔎 مقدار مبلغ خود را ارسال کنید :", $back_panel);
        } else {
            sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
        }
    }
    
    elseif ($user['step'] == 'add_coin2') {
        step('none');
        $id = file_get_contents('id.txt');
        $sql->query("UPDATE `users` SET `coin` = coin + $text WHERE `from_id` = '$id'");
        sendMessage($from_id, "✅ با موفقیت انجام شد.", $manage_user);
        sendMessage($id, "✅ حساب شما از طرف مدیریت به مقدار <code>$text</code> تومان شارژ شد.");
        unlink('id.txt');
    }
    
    elseif ($text == '➖ کسر موجودی') {
        step('rem_coin');
        sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'rem_coin' and $text != '⬅️ بازگشت به مدیریت') {
        $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
        if($user->num_rows > 0){
            step('rem_coin2');
            file_put_contents('id.txt', $text);
            sendMessage($from_id, "🔎 مقدار مبلغ خود را ارسال کنید :", $back_panel);
        } else {
            sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
        }
    }
    
    elseif ($user['step'] == 'rem_coin2' and $text != '⬅️ بازگشت به مدیریت') {  
        step('none');
        $id = file_get_contents('id.txt');
        $sql->query("UPDATE `users` SET `coin` = coin - $text WHERE `from_id` = '$id'");
        sendMessage($from_id, "✅ با موفقیت انجام شد.", $manage_user);
        sendMessage($id, "✅ از طرف مدیریت مقدار <code>$text</code> تومان از حساب شما کسر شد.");
        unlink('id.txt');
    }
    
    elseif (strpos($data, 'cancel_fish') !== false) {
        $id = explode('-', $data)[1];
        editMessage($from_id, "✅ با موفقیت انجام شد !", $message_id);
        sendMessage($id, "❌ فیش ارسالی شما به دلیل اشتباه بودن از طرف مدیریت لغو شد و حساب شما شارژ نشد !");
    }
    
    elseif (strpos($data, 'accept_fish') !== false) {
        $id = explode('-', $data)[1];
        $price = explode('-', $data)[2];
        $sql->query("UPDATE `users` SET `coin` = coin + $price WHERE `from_id` = '$id'");
        editMessage($from_id, "✅ با موفقیت انجام شد !", $message_id);
        sendMessage($id, "✅ حساب شما با موفقیت به مبلغ <code>$price</code> تومان شارژ شد !");
    }
    
    elseif ($text == '❌ مسدود کردن') {
        step('block');
        sendMessage($from_id, "🔢 ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'block' and $text != '⬅️ بازگشت به مدیریت') {
        $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
        if ($user->num_rows > 0) {
            step('none');
            $sql->query("UPDATE `users` SET `status` = 'inactive' WHERE `from_id` = '$text'");
            sendMessage($from_id, "✅ کاربر مورد نظر با موفقیت بلاک شد.", $manage_user);
        } else {
            sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
        }
    }
    
    elseif ($text == '✅ آزاد کردن') {
        step('unblock');
        sendmessage($from_id, "🔢 ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'unblock' and $text != '⬅️ بازگشت به مدیریت' ){
        $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
        if ($user->num_rows > 0) {
            step('none');
            $sql->query("UPDATE `users` SET `status` = 'active' WHERE `from_id` = '$text'");
            sendMessage($from_id, "✅ کاربر مورد نظر با موفقیت ازاد شد.", $manage_user);
        } else {
            sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);  
        }
    }
    
    // ----------- manage setting ----------- //
    elseif ($text == '◽بخش ها') {
        sendMessage($from_id, "🔰این بخش تکمیل نشده است !");
    }
    
    elseif ($text == '◽کانال ها') {    
        $lockSQL = $sql->query("SELECT `chat_id`, `name` FROM `lock`");
        if (mysqli_num_rows($lockSQL) > 0) {
            $locksText = "☑️ به بخش (🔒 بخش قفل ها) خوش امدید\n\n🚦 راهنما :\n1 - 👁 برای مشاهده ی هر کدام روی اسم ان بزنید.\n2 - برای حذف هر کدام روی دکمه ی ( 🗑 ) بزنید\n3 - برای افزودن قفل روی دکمه ی ( ➕ افزودن قفل ) بزنید";
            $button[] = [['text' => '🗝 نام قفل', 'callback_data' => 'none'], ['text' => '🗑 حذف', 'callback_data' => 'none']];
            while ($row = $lockSQL->fetch_assoc()) {
                $name = $row['name'];
                $link = str_replace("@", "", $row['chat_id']);
                $button[] = [['text' => $name, 'url' => "https://t.me/$link"], ['text' => '🗑', 'callback_data' => "remove_lock-{$row['chat_id']}"]];
            }
        } else $locksText = '❌ شما قفلی برای حذف و مشاهده ندارید لطفا از طریق دکمه ی ( ➕ افزودن قفل ) اضافه کنید.';
        $button[] = [['text' => '➕ افزودن قفل', 'callback_data' => 'addLock']];
        if ($data) editmessage($from_id, $locksText, $message_id, json_encode(['inline_keyboard' => $button]));
        else sendMessage($from_id, $locksText, json_encode(['inline_keyboard' => $button]));
    }
    
    elseif($data == 'addLock'){
        step('add_channel');
        deleteMessage($from_id, $message_id);
        sendMessage($from_id, "✔ یوزرنیم کانال خود را با @ ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'add_channel' and $data != 'back_look' and $text != '⬅️ بازگشت به مدیریت') {
        if (strpos($text, "@") !== false) { 
            if ($sql->query("SELECT * FROM `lock` WHERE `chat_id` = '$text'")->num_rows == 0) {
                $info_channel = bot('getChatMember', ['chat_id' => $text, 'user_id' => bot('getMe')->result->id]);
                if ($info_channel->result->status == 'administrator') {
                    step('none');
                    $channel_name = bot('getChat', ['chat_id' => $text])->result->title ?? 'بدون نام';
                    $sql->query("INSERT INTO `lock`(`name`, `chat_id`) VALUES ('$channel_name', '$text')");
                    $txt = "✅ کانال شما با موفقیت به لیست جوین اجباری اضافه شد.\n\n🆔 - $text";
                    sendmessage($from_id, $txt, $panel);
                } else { 
                    sendMessage($from_id, "❌  ربات داخل کانال $text ادمین نیست !", $back_panel);
                }
            } else {
                sendMessage($from_id, "❌ این کانال از قبل در ربات ثبت شده است !", $back_panel);
            }
        } else {
            sendmessage($from_id, "❌ یوزرنیم ارسالی شما باید با @ باشد !", $back_panel);
        }
    }
    
    elseif (strpos($data, "remove_lock-") !== false) {
        $link = explode("-", $data)[1];
        $sql->query("DELETE FROM `lock` WHERE `chat_id` = '$link' LIMIT 1");
        $lockSQL = $sql->query("SELECT `chat_id`, `name` FROM `lock`");
        if (mysqli_num_rows($lockSQL) > 0) {
            $locksText = "☑️ به بخش (🔒 بخش قفل ها) خوش امدید\n\n🚦 راهنما :\n1 - 👁 برای مشاهده ی هر کدام روی اسم ان بزنید.\n2 - برای حذف هر کدام روی دکمه ی ( 🗑 ) بزنید\n3 - برای افزودن قفل روی دکمه ی ( ➕ افزودن قفل ) بزنید";
            $button[] = [['text' => '🗝 نام قفل', 'callback_data' => 'none'], ['text' => '🗑 حذف', 'callback_data' => 'none']];
            while ($row = $lockSQL->fetch_assoc()) {
                $name = $row['name'];
                $link = str_replace("@", "", $row['chat_id']);
                $button[] = [['text' => $name, 'url' => "https://t.me/$link"], ['text' => '🗑', 'callback_data' => "remove_lock_{$row['chat_id']}"]];
            }
        } else $locksText = '❌ شما قفلی برای حذف و مشاهده ندارید لطفا از طریق دکمه ی ( ➕ افزودن قفل ) اضافه کنید.';
        $button[] = [['text' => '➕ افزودن قفل', 'callback_data' => 'addLock']];
        if ($data) editmessage($from_id, $locksText, $message_id, json_encode(['inline_keyboard' => $button]));
        else sendMessage($from_id, $locksText, json_encode(['inline_keyboard' => $button]));
    }
    
    // ----------------- manage paymanet ----------------- //
    elseif ($text == '◽تنظیمات درگاه پرداخت') {
        sendMessage($from_id, "⚙️️ به تنظیمات درگاه پرداخت خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_payment);
    }
    
    elseif ($text == '✏️ وضعیت خاموش/روشن درگاه پرداخت های ربات') {
        sendMessage($from_id, "🆙 این قسمت در نسخه های بعدی اضافه خواهد شد !");
    }
    
    elseif ($text == '▫️تنظیم شماره کارت') {
        step('set_card_number');
        sendMessage($from_id, "🪪 لطفا شماره کارت خود را به صورت صحیح و دقیق ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'set_card_number') {
        if (is_numeric($text)) {
            step('none');
            $sql->query("UPDATE `payment_setting` SET `card_number` = '$text'");
            sendMessage($from_id, "✅ شماره کارت ارسالی شما با موفقیت تنظیم شد !\n\n◽️شماره کارت : <code>$text</code>", $manage_payment);
        } else {
            sendMessage($from_id, "❌ شماره کارت ارسالی شما اشتباه است !", $back_panel);
        }
    }
    
    elseif ($text == '▫️تنظیم صاحب شماره کارت') {
        step('set_card_number_name');
        sendMessage($from_id, "#️⃣ نام صاحب کارت را به صورت دقیق و صحیح ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'set_card_number_name') {
        step('none');
        $sql->query("UPDATE `payment_setting` SET `card_number_name` = '$text'");
        sendMessage($from_id, "✅ صاحب شماره کارت ارسالی شما با موفقیت تنظیم شد !\n\n◽صاحب ️شماره کارت : <code>$text</code>", $manage_payment);
    }
    
    elseif ($text == '▫️آیدی پی') {
        step('set_idpay_token');
        sendMessage($from_id, "🔎 لطفا api_key آیدی پی خود را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'set_idpay_token') {
        step('none');
        $sql->query("UPDATE `payment_setting` SET `idpay_token` = '$text'");
        sendMessage($from_id, "✅ با موفقیت تنظیم شد !", $manage_payment);
    }
    
    elseif ($text == '▫️زرین پال') {
        step('set_zarinpal_token');
        sendMessage($from_id, "🔎 لطفا api_key زرین پال خود را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'set_zarinpal_token') {
        step('none');
        $sql->query("UPDATE `payment_setting` SET `zarinpal_token` = '$text'");
        sendMessage($from_id, "✅ با موفقیت تنظیم شد !", $manage_payment);
    }
    
    // -----------------manage texts ----------------- //
    elseif ($text == '◽تنظیم متون ربات') {
        sendMessage($from_id, "⚙️️ به تنظیمات متون ربات خوش آمدید.\n\n👇🏻یکی از گزینه های زیر را انتخاب کنید :", $manage_texts);
    }
    
    elseif ($text == 'متن استارت') {
        step('set_start_text');
        sendMessage($from_id, "👇 متن استارت را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'set_start_text') {
        step('none');
        $texts['start'] = str_replace('
        ', '\n', $text);
        file_put_contents('texts.json', json_encode($texts));
        sendMessage($from_id, "✅ متن استارت با موفقیت تنظیم شد !", $manage_texts);
    }
    
    elseif ($text == 'متن تعرفه خدمات') {
        step('set_tariff_text');
        sendMessage($from_id, "👇 متن تعرفه خدمات را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'set_tariff_text') {
        step('none');
        $texts['service_tariff'] = str_replace('
        ', '\n', $text);
        file_put_contents('texts.json', json_encode($texts));
        sendMessage($from_id, "✅ متن تعرفه خدمات با موفقیت تنظیم شد !", $manage_text);
    }
    
    // -----------------manage admins ----------------- //
    elseif ($text == '➕ افزودن ادمین') {
        step('add_admin');
        sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'add_admin' and $text != '⬅️ بازگشت به مدیریت') {
        $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
        if($user->num_rows != 0){
            step('none');
            $sql->query("INSERT INTO `admins` (`chat_id`) VALUES ('$text')");
            sendMessage($from_id, "✅ کاربر <code>$text</code> با موفقیت به لیست ادمین ها اضافه شد.", $manage_admin);
        } else {  
            sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);
        }
    }
    
    elseif ($text == '➖ حذف ادمین') {
        step('rem_admin');
        sendMessage($from_id, "🔰ایدی عددی کاربر مورد نظر را ارسال کنید :", $back_panel);
    }
    
    elseif ($user['step'] == 'rem_admin' and $text != '⬅️ بازگشت به مدیریت') {
        $user = $sql->query("SELECT * FROM `users` WHERE `from_id` = '$text'");
        if($user->num_rows > 0){
            step('none');
            $sql->query("DELETE FROM `admins` WHERE `chat_id` = '$text'");
            sendMessage($from_id, "✅ کاربر <code>$text</code> با موفقیت از لیست ادمین ها حذف شد.", $manage_admin);
        } else {
            sendMessage($from_id, "‼ کاربر <code>$text</code> عضو ربات نیست !", $back_panel);  
        }
        
    }
    
    elseif ($text == '⚙️ لیست ادمین ها') {
        $res = $sql->query("SELECT * FROM `admins`");
        if($res->num_rows == 0){
            sendmessage($from_id, "❌ لیست ادمین های ربات خالی است.");
            exit();
        }
        while($row = $res->fetch_array()){
            $key[] = [['text' => $row['chat_id'], 'callback_data' => 'delete_admin-'.$row['chat_id']]];
        }
        $count = $res->num_rows;
        $key = json_encode(['inline_keyboard' => $key]);
        sendMessage($from_id, "🔰لیست ادمین های ربات به شرح زیر است :\n\n🔎 تعداد کل ادمین ها : <code>$count</code>", $key);
    }
}

/**
* Project name: ZanborPanel
* Channel: @ZanborPanel
* Group: @ZanborPanelGap
 * Version: 1.0.0
**/

?>
