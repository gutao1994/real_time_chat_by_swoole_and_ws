<?php  

$server = new swoole_websocket_server("0.0.0.0", 9501);

$server->set([
	'reactor_num' => 2,
	'worker_num' => 3,
	'task_worker_num' => 4,
	'daemonize' => false,
	'log_file' => '/usr/local/apache2/htdocs/swoole/room/swoole.log',
	'heartbeat_check_interval' => 10, //心跳检测
	'heartbeat_idle_time' => 20,
	'user' => 'apache',
	'group' => 'apache',
	'package_max_length' => 5 * 1024 * 1024,
	'buffer_output_size' => 4 * 1024 * 1024,
	]);

$server->on('open', function (swoole_websocket_server $server, $request) {
    // echo $request->fd;
});

$server->on('message', function (swoole_websocket_server $server, $frame) {
	$fd = $frame->fd;
	$data = json_decode($frame->data, true);

	if($data['type'] == 'login'){ //接收登录信息
		$uid = $data['uid'];
		$username = $data['username'];
		$group_id = $data['group_id'];

		loginRoom($fd, $uid, $username, $group_id, $server);
	}else if($data['type'] == 'msg'){ //接收用户发送的消息
		$from_uid = $data['from_uid'];
		$from_username = $data['from_username'];
		$group_id = $data['group_id'];
		$msg = $data['msg'];

		send_sms($from_username, $group_id, $msg, $server);
	}else if($data['type'] == 'image'){ //发送大点的数据使用task异步发送
		$server->task($frame->data);
	}else if($data['type'] == 'tuya'){ //发送大点的数据使用task异步发送
		$server->task($frame->data);
	}else if($data['type'] == 'ice'){ //接收ice候选信息
		if($data['role'] == 'caller'){
			receive_calller_ice($data);
		}
	}else if($data['type'] == 'offer'){ //接收caller的offer sdp信息
		receive_calller_sdp($data);
	}
});

$server->on('task', function ($serv, $task_id, $from_id, $data){
	$data = json_decode($data, true);

	switch ($data['type']){
		case 'image':
			sendImage2Group ($serv, $data['from_username'], $data['group_id'], $data['dataUrl']);
			break;
		case 'tuya':
			sendTuya2Group ($serv, $data['from_username'], $data['group_id'], $data['dataUrl']);
			break;
	}

	$serv->finish('response');
});

$server->on('finish', function ($serv, $task_id, $data){

});

$server->on('close', function ($ser, $fd) {
	logout($fd, $ser);
});

$server->start();

/*-----------------------分割线--------------------------*/

function loginRoom($fd, $uid, $username, $group_id, $server){
	$redis = getRedis();

	$is_in_room = $redis->sIsMember('roomAll', $uid);
	if($is_in_room){ //已经在其他地方登录了
		$data = [
			'type' => 'hasLogin',
		];
		$server->push($fd, json_encode($data));
		return ;
	}

	$redis->sAdd('roomAll', $uid);

	$room = 'room' . $group_id; //房间号

	$all_members = $redis->sMembers($room);
	
	//将当前用户加入到该组中
	$tmp_join_data = fd_uid_username($fd, $uid, $username); //组织数据
	$redis->sAdd($room, $tmp_join_data);

	if(count($all_members) > 1){ //该房间已经有其他用户
		//向其他用户通知有用户登录了
		$all_members = removeValueFromArr($all_members, 'none');
		$username_list = [];

		$send_data = [
			'type' => 'someoneComing',
			'username' => $username,
		];
		$send_data = json_encode($send_data);

		foreach($all_members as $val){
			$tmp_val = rev_uid_username($val);
			$username_list[] = $tmp_val['username'];
			$server->push($tmp_val['fd'], $send_data);
		}

		//向当前登录的用户发送在线用户列表
		$username_list[] = $username;
		$cur_send_data = [
			'type' => 'userlistForLogin',
			'data' => $username_list,
		];
		$server->push($fd, json_encode($cur_send_data));
	}else{ //该房间还没有人，当前用户是第一位用户
		$send_data = ['type' => 'first_one'];
		$server->push($fd, json_encode($send_data));
	}
}

//向同一房间的人发送信息
function send_sms($from_username, $group_id, $msg, $server){
	$redis = getRedis();

	$room = 'room' . $group_id; //房间号
	$all_members = $redis->sMembers($room);
	$all_members = removeValueFromArr($all_members, 'none');

	$send_data = [
		'type' => 'msg',
		'from_username' => $from_username,
		'msg' => $msg,
		'time' => date('Y-m-d H:i:s'),
	];
	$send_data = json_encode($send_data);

	foreach($all_members as $val){
		$tmp_val = rev_uid_username($val);
		$server->push($tmp_val['fd'], $send_data);
	}
}

//用户退出房间，向该房间内所有人发送退出消息
function logout($fd, $server){
	$redis = getRedis();

	$room1_mem = $redis->sMembers('room1');
	$room1_mem = removeValueFromArr($room1_mem, 'none');

	if(!empty($room1_mem)){
		foreach($room1_mem as $key => $val){
			$tmp_val = rev_uid_username($val);
			if($tmp_val['fd'] == $fd){ //找到了客户端所在的组
				$redis->sRem('room1', $val);
				$redis->sRem('roomAll', $tmp_val['uid']);

				unset($room1_mem[$key]);

				if(!empty($room1_mem)){
					$ret_data = [
						'type' => 'logout',
						'username' => $tmp_val['username'],
					];
					$ret_data = json_encode($ret_data);

					foreach($room1_mem as $v_val){
						$v_val = rev_uid_username($v_val);
						$server->push($v_val['fd'], $ret_data);
					}
				}
				return;
			}
		}
	}

	$room2_mem = $redis->sMembers('room2');
	$room2_mem = removeValueFromArr($room2_mem, 'none');

	if(!empty($room2_mem)){
		foreach($room2_mem as $key => $val){
			$tmp_val = rev_uid_username($val);
			if($tmp_val['fd'] == $fd){
				$redis->sRem('room2', $val);
				$redis->sRem('roomAll', $tmp_val['uid']);

				unset($room2_mem[$key]);

				if(!empty($room2_mem)){
					$ret_data = [
						'type' => 'logout',
						'username' => $tmp_val['username'],
					];
					$ret_data = json_encode($ret_data);

					foreach($room2_mem as $v_val){
						$v_val = rev_uid_username($v_val);
						$server->push($v_val['fd'], $ret_data);
					}
				}
				return ;
			}
		}
	}

	$room3_mem = $redis->sMembers('room3');
	$room3_mem = removeValueFromArr($room3_mem, 'none');

	if(!empty($room3_mem)){
		foreach($room3_mem as $key => $val){
			$tmp_val = rev_uid_username($val);
			if($tmp_val['fd'] == $fd){
				$redis->sRem('room3', $val);
				$redis->sRem('roomAll', $tmp_val['uid']);

				unset($room3_mem[$key]);

				if(!empty($room3_mem)){
					$ret_data = [
						'type' => 'logout',
						'username' => $tmp_val['username'],
					];
					$ret_data = json_encode($ret_data);

					foreach($room3_mem as $v_val){
						$v_val = rev_uid_username($v_val);
						$server->push($v_val['fd'], $ret_data);
					}
				}
				return ;
			}
		}
	}
}

//发送图片到房间
function sendImage2Group ($serv, $username, $group_id, $imageData){
	$redis = getRedis();
	$room = 'room' . $group_id; //房间号
	$all_members = $redis->sMembers($room);
	$all_members = removeValueFromArr($all_members, 'none');

	$all_length = strlen($imageData); //图片数据的长度

	if($all_length >= 60000){ //图片数据太长，开始分段发送
		$split_length = 60000;
		$num = ceil($all_length / $split_length);

		$send_data = [
			'type' => 'image',
			'from_username' => $username,
			'dataUrl' => '',
			'time' => date('Y-m-d H:i:s'),
			'is_end' => false,
		];

		foreach($all_members as $val){
			$tmp_val = rev_uid_username($val);
			$send_data['is_end'] = false;
			$send_data['dataUrl'] = '';

			for($i=0; $i<$num; $i++){ //循环按段来发送数据
				if($i == ($num-1)){ //最后一段数据
					$send_data['is_end'] = true;
				}else{
					$send_data['is_end'] = false;
				}

				$send_data['dataUrl'] = substr($imageData, $i * $split_length, $split_length); //分段
				$send_data_tmp = json_encode($send_data);
				$serv->push($tmp_val['fd'], $send_data_tmp);
			}
		}
	}else{
		$send_data = [
			'type' => 'image',
			'from_username' => $username,
			'dataUrl' => $imageData,
			'time' => date('Y-m-d H:i:s'),
		];
		$send_data = json_encode($send_data);

		foreach ($all_members as $val){
			$tmp_val = rev_uid_username($val);
			$serv->push($tmp_val['fd'], $send_data);
		}
	}
}

//发送涂鸦到房间(同上)
function sendTuya2Group ($serv, $username, $group_id, $imageData){
	$redis = getRedis();
	$room = 'room' . $group_id; //房间号
	$all_members = $redis->sMembers($room);
	$all_members = removeValueFromArr($all_members, 'none');

	$all_length = strlen($imageData); //图片数据的长度

	if($all_length >= 60000){ //图片数据太长，开始分段发送
		$split_length = 60000;
		$num = ceil($all_length / $split_length);

		$send_data = [
			'type' => 'tuya',
			'from_username' => $username,
			'dataUrl' => '',
			'time' => date('Y-m-d H:i:s'),
			'is_end' => false,
		];

		foreach($all_members as $val){
			$tmp_val = rev_uid_username($val);
			$send_data['is_end'] = false;
			$send_data['dataUrl'] = '';

			for($i=0; $i<$num; $i++){ //循环按段来发送数据
				if($i == ($num-1)){ //最后一段数据
					$send_data['is_end'] = true;
				}else{
					$send_data['is_end'] = false;
				}

				$send_data['dataUrl'] = substr($imageData, $i * $split_length, $split_length); //分段
				$send_data_tmp = json_encode($send_data);
				$serv->push($tmp_val['fd'], $send_data_tmp);
			}
		}
	}else{
		$send_data = [
			'type' => 'tuya',
			'from_username' => $username,
			'dataUrl' => $imageData,
			'time' => date('Y-m-d H:i:s'),
		];
		$send_data = json_encode($send_data);

		foreach ($all_members as $val){
			$tmp_val = rev_uid_username($val);
			$serv->push($tmp_val['fd'], $send_data);
		}
	}
}

/*
一次视频通话需要维护的数据
video:group_id:1:from_username:gutao:to_username:xuyan {
	caller => {group_id:1,username:gutao,fd:12,candidate:xxx,sdp:xxx}
	callee => {group_id:1,username:gutao,fd:12,candidate:xxx,sdp:xxx}
}
*/

//接收caller的ice信息
function receive_calller_ice ($data){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$from_username = $data['from_username'];
	$to_username = $data['to_username'];

	$key = 'video:group_id:' . $group_id . ':from_username:' . $from_username . ':to_username:' . $to_username;

	if($redis->exists($key)){ //键已经存在，使用存在的键
		if($redis->hExists($key, 'caller')){ //检测calller域是否存在
			$caller_val = $redis->hGet($key, 'caller');
			$caller_val = json_decode($caller_val, true);

			$caller_val['group_id'] = $data['group_id'];
			$caller_val['username'] = $data['from_username'];
			$caller_val['candidate'] = $data['candidate'];
			$caller_val[''] = 

		}else{ //不存在caller域

		}
	}else{ //键不存在，新建一个键

	}
}






/********* 一些常用的函数 **********/

//从数组中移除某一个值
function removeValueFromArr ($arr, $val){
	$index = array_search($val, $arr);
	if($index !== false){
		unset($arr[$index]);
	}

	return $arr;
}

//将用户信息转换为字符串格式
function fd_uid_username ($fd, $uid, $username){
	$tmp_arr = [
		'fd' => $fd,
		'uid' => $uid,
		'username' => $username,
	];

	$tmp_arr_str = json_encode($tmp_arr);
	return $tmp_arr_str;
}

//将用户信息字符串格式转为正常数组格式
function rev_uid_username ($str){
	return json_decode($str, true);
}

//获取redis实例
function getRedis (){
	$redis = new Redis();
	$redis->connect('127.0.0.1', 6379);
	return $redis;
}

//通过房间号和用户名获取该用户的fd
function getFdByGroupIdAndUsername ($group_id, $username){
	$redis = getRedis();

	$room = 'room' . $group_id;
	$all_members = $redis->sMembers($room);
	$all_members = removeValueFromArr($all_members, 'none');

	if(!empty($all_members)){
		$has = false;

		foreach($all_members as $val){
			$tmp_val = rev_uid_username($val);
			if($tmp_val['username'] == $username){
				$fd = $tmp_val['fd'];
				$has = true;
				break;
			}
		}

		if($has){
			return $fd;
		}else{
			return false;
		}
	}else{
		return false;
	}
}
















?>