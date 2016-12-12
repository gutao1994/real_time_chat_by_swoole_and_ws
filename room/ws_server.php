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
		if($data['role'] == 'start_caller'){ //接收start_caller的ice候选信息
			receive_start_caller_ice($data);

		}else if($data['role'] == 'start_callee'){ //接收start_callee的ice候选信息
			receive_start_callee_ice($data, $server);

		}else if($data['role'] == 'reply_caller'){ //接收reply_caller发起的视频回话的ice候选信息(与原先的方向相反)
			receive_reply_caller_ice($data);

		}else if($data['role'] == 'reply_callee'){
			receive_reply_callee_ice($data, $server);

		}
	}else if($data['type'] == 'sdp'){
		if($data['role'] == 'start_caller_offer'){ //接收start_caller的offer sdp信息
			receive_start_caller_sdp($data, $server, $fd);

		}else if($data['role'] == 'start_callee_answer'){ //接收start_callee的answer sdp信息
			receive_start_callee_sdp($data, $server);

		}else if($data['role'] == 'reply_caller_offer'){ //接收reply_caller的offer sdp信息 
			receive_reply_caller_sdp($data, $server);

		}else if($data['role'] == 'reply_callee_answer'){ //接收reply_callee的answer sdp信息
			receive_reply_callee_sdp($data, $server);

		}
	}else if($data['type'] == 'refuse_video_invite'){ //拒绝了视频通话的邀请
		refuse_video_invite($data, $server);
	}else if($data['type'] == 'not_firefox_reply'){ //start_callee不是火狐浏览器，因此不能共享自己的摄像头
		not_firefox_reply($data, $server);
	}else if($data['type'] == 'is_firefox_but_error'){ //reply_caller是火狐浏览器，但是调用摄像头失败
		is_firefox_but_error($data, $server);
	}else if($data['type'] == 'close_video_chat'){ //关闭视频通话
		close_video_chat($data, $server);
	}else if($data['type'] == 'clear_start_caller_info'){ //start_caller调用摄像头失败，需要清除对应的信息
		clear_start_caller_info($data);
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

				del_key_as_video(1, $tmp_val['username'], $server);
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

				del_key_as_video(2, $tmp_val['username'], $server);
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

				del_key_as_video(3, $tmp_val['username'], $server);
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

/********************视频通话部分函数*************************/
//接收start_caller的ice信息
function receive_start_caller_ice ($data){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$start_caller_username = $data['start_caller_username'];
	$start_callee_username = $data['start_callee_username'];
	$candidate = $data['candidate'];

	$key = 'start_video_group_' . $group_id . '_caller_' . $start_caller_username . '_callee_' . $start_callee_username;

	//检查start_callee是否已经处于视频通话中
	if(checkUsernameIsInVideo($group_id, $start_callee_username)){
		$redis->delete($key);
		return false;
	}

	if($redis->exists($key) && $redis->hExists($key, 'caller')){ //键已经存在，并且caller域也存在
		$caller_val = $redis->hGet($key, 'caller');
		$caller_val = json_decode($caller_val, true);
	}else{
		$caller_val = [];
	}

	$caller_val['group_id'] = $group_id;
	$caller_val['username'] = $start_caller_username;
	$caller_val['fd'] = getFdByGroupIdAndUsername($group_id, $start_caller_username);
	$caller_val['candidate'] = $candidate;
	
	$redis->hSet($key, 'caller', json_encode($caller_val));
}

//接收start_callee的ice信息
function receive_start_callee_ice($data, $server){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$start_caller_username = $data['start_caller_username'];
	$start_callee_username = $data['start_callee_username'];
	$candidate = $data['candidate'];

	$key = 'start_video_group_' . $group_id . '_caller_' . $start_caller_username . '_callee_' . $start_callee_username;

	if($redis->exists($key) && $redis->hExists($key, 'callee')){ //键已经存在，并且caller域也存在
		$callee_val = $redis->hGet($key, 'callee');
		$callee_val = json_decode($callee_val, true);
	}else{
		$callee_val = [];
	}

	$callee_val['group_id'] = $group_id;
	$callee_val['username'] = $start_callee_username;
	$callee_val['fd'] = getFdByGroupIdAndUsername($group_id, $start_callee_username);
	$callee_val['candidate'] = $candidate;
	
	$redis->hSet($key, 'callee', json_encode($callee_val));

	//发送给start_caller关于start_callee的ice结果
	$caller_fd = getFdByGroupIdAndUsername($group_id, $start_caller_username);
	$send_ice_data_to_caller = [
		'type' => 'ice_res',
		'role' => 'start_caller',
		'candidate' => $candidate,
	];
	$server->push($caller_fd, json_encode($send_ice_data_to_caller));

	//发送给start_callee关于start_caller的ice结果
	$caller_info = $redis->hGet($key, 'caller');
	$caller_info = json_decode($caller_info, true);
	$send_ice_data_to_callee = [
		'type' => 'ice_res',
		'role' => 'start_callee',
		'candidate' => $caller_info['candidate'],
	];
	$server->push($callee_val['fd'], json_encode($send_ice_data_to_callee));
}

//接收reply_caller的ice信息
function receive_reply_caller_ice($data){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$reply_caller_username = $data['reply_caller_username'];
	$reply_callee_username = $data['reply_callee_username'];
	$candidate = $data['candidate'];

	$key = 'reply_video_group_' . $group_id . '_caller_' . $reply_caller_username . '_callee_' . $reply_callee_username;

	if($redis->exists($key) && $redis->hExists($key, 'caller')){
		$caller_val = $redis->hGet($key, 'caller');
		$caller_val = json_decode($caller_val, true);
	}else{
		$caller_val = [];
	}

	$caller_val['group_id'] = $group_id;
	$caller_val['username'] = $reply_caller_username;
	$caller_val['fd'] = getFdByGroupIdAndUsername($group_id, $reply_caller_username);
	$caller_val['candidate'] = $candidate;
	
	$redis->hSet($key, 'caller', json_encode($caller_val));
}

//接收reply_callee的ice信息
function receive_reply_callee_ice($data, $server){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$reply_caller_username = $data['reply_caller_username'];
	$reply_callee_username = $data['reply_callee_username'];
	$candidate = $data['candidate'];

	$key = 'reply_video_group_' . $group_id . '_caller_' . $reply_caller_username . '_callee_' . $reply_callee_username;

	if($redis->exists($key) && $redis->hExists($key, 'callee')){
		$callee_val = $redis->hGet($key, 'callee');
		$callee_val = json_decode($callee_val, true);
	}else{
		$callee_val = [];
	}

	$callee_val['group_id'] = $group_id;
	$callee_val['username'] = $reply_callee_username;
	$callee_val['fd'] = getFdByGroupIdAndUsername($group_id, $reply_callee_username);
	$callee_val['candidate'] = $candidate;
	
	$redis->hSet($key, 'callee', json_encode($callee_val));

	//发送给reply_caller关于reply_callee的ice结果
	$caller_fd = getFdByGroupIdAndUsername($group_id, $reply_caller_username);
	$send_ice_data_to_caller = [
		'type' => 'ice_res',
		'role' => 'reply_caller',
		'candidate' => $candidate,
	];
	$server->push($caller_fd, json_encode($send_ice_data_to_caller));

	//发送给reply_callee关于reply_caller的ice结果
	$caller_info = $redis->hGet($key, 'caller');
	$caller_info = json_decode($caller_info, true);
	$send_ice_data_to_callee = [
		'type' => 'ice_res',
		'role' => 'reply_callee',
		'candidate' => $caller_info['candidate'],
	];
	$server->push($callee_val['fd'], json_encode($send_ice_data_to_callee));
}

//接收start_caller的sdp信息 
function receive_start_caller_sdp($data, $server, $fd){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$start_caller_username = $data['start_caller_username'];
	$start_callee_username = $data['start_callee_username'];
	$sdp = $data['sdp'];

	$key = 'start_video_group_' . $group_id . '_caller_' . $start_caller_username . '_callee_' . $start_callee_username;

	//检查start_callee是否已经处于视频通话中
	if(checkUsernameIsInVideo($group_id, $start_callee_username, $server, $fd, true)){
		$redis->delete($key);
		return false;
	}

	if($redis->exists($key) && $redis->hExists($key, 'caller')){ //同上
		$caller_val = $redis->hGet($key, 'caller');
		$caller_val = json_decode($caller_val, true);
	}else{
		$caller_val = [];
	}

	$caller_val['group_id'] = $group_id;
	$caller_val['username'] = $start_caller_username;
	$caller_val['fd'] = getFdByGroupIdAndUsername($group_id, $start_caller_username);
	$caller_val['sdp'] = $sdp;
	
	$redis->hSet($key, 'caller', json_encode($caller_val));

	$send_to_fd = getFdByGroupIdAndUsername($group_id, $start_callee_username);
	send_video_invite_msg($server, $send_to_fd, $start_caller_username, $sdp);
}

//接收start_callee的answer sdp信息，并且把start_callee的answer sdp发送到start_caller
function receive_start_callee_sdp($data, $server){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$start_caller_username = $data['start_caller_username'];
	$start_callee_username = $data['start_callee_username'];
	$sdp = $data['sdp'];

	$key = 'start_video_group_' . $group_id . '_caller_' . $start_caller_username . '_callee_' . $start_callee_username;

	if($redis->exists($key) && $redis->hExists($key, 'callee')){ //同上
		$callee_val = $redis->hGet($key, 'callee');
		$callee_val = json_decode($callee_val, true);
	}else{
		$callee_val = [];
	}

	$callee_val['group_id'] = $group_id;
	$callee_val['username'] = $start_callee_username;
	$callee_val['fd'] = getFdByGroupIdAndUsername($group_id, $start_callee_username);
	$callee_val['sdp'] = $sdp;
	
	$redis->hSet($key, 'callee', json_encode($callee_val));

	$send_data = [
		'type' => 'return_start_callee_sdp',
		'sdp' => $sdp,
	];
	$caller_fd = getFdByGroupIdAndUsername($group_id, $start_caller_username);
	$server->push($caller_fd, json_encode($send_data));
}

//接收reply_caller的sdp信息
function receive_reply_caller_sdp($data, $server){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$reply_caller_username = $data['reply_caller_username'];
	$reply_callee_username = $data['reply_callee_username'];
	$sdp = $data['sdp'];

	$key = 'reply_video_group_' . $group_id . '_caller_' . $reply_caller_username . '_callee_' . $reply_callee_username;

	if($redis->exists($key) && $redis->hExists($key, 'caller')){ //同上
		$caller_val = $redis->hGet($key, 'caller');
		$caller_val = json_decode($caller_val, true);
	}else{
		$caller_val = [];
	}

	$caller_val['group_id'] = $group_id;
	$caller_val['username'] = $reply_caller_username;
	$caller_val['fd'] = getFdByGroupIdAndUsername($group_id, $reply_caller_username);
	$caller_val['sdp'] = $sdp;
	
	$redis->hSet($key, 'caller', json_encode($caller_val));

	$send_to_fd = getFdByGroupIdAndUsername($group_id, $reply_callee_username);
	send_video_callback_msg($server, $send_to_fd, $reply_caller_username, $sdp);
}

//接收reply_callee的sdp信息，并把reply_callee的sdp信息发送到reply_caller，完成最后一步sdp交换
function receive_reply_callee_sdp($data, $server){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$reply_caller_username = $data['reply_caller_username'];
	$reply_callee_username = $data['reply_callee_username'];
	$sdp = $data['sdp'];

	$key = 'reply_video_group_' . $group_id . '_caller_' . $reply_caller_username . '_callee_' . $reply_callee_username;

	if($redis->exists($key) && $redis->hExists($key, 'callee')){ //同上
		$callee_val = $redis->hGet($key, 'callee');
		$callee_val = json_decode($callee_val, true);
	}else{
		$callee_val = [];
	}

	$callee_val['group_id'] = $group_id;
	$callee_val['username'] = $reply_callee_username;
	$callee_val['fd'] = getFdByGroupIdAndUsername($group_id, $reply_callee_username);
	$callee_val['sdp'] = $sdp;
	
	$redis->hSet($key, 'callee', json_encode($callee_val));

	$send_data = [
		'type' => 'return_reply_callee_sdp',
		'sdp' => $sdp, 
	];
	$caller_fd = getFdByGroupIdAndUsername($group_id, $reply_caller_username);
	$server->push($caller_fd, json_encode($send_data));
}

//发送视频通话邀请信息
function send_video_invite_msg ($server, $fd, $start_caller_username, $sdp){
	$send_data = [
		'type' => 'video_invite',
		'start_caller_username' => $start_caller_username,
		'sdp' => $sdp,
	];

	$server->push($fd, json_encode($send_data));
}

//发送视频通话回调sdp信息
function send_video_callback_msg ($server, $fd, $reply_caller_username, $sdp){
	$send_data = [
		'type' => 'video_callback',
		'reply_caller_username' => $reply_caller_username,
		'sdp' => $sdp,
	];

	$server->push($fd, json_encode($send_data));
}

//拒绝了视频通话的邀请
function refuse_video_invite($data, $server){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$start_caller_username = $data['start_caller_username'];
	$start_callee_username = $data['start_callee_username'];

	$key = 'start_video_group_' . $group_id . '_caller_' . $start_caller_username . '_callee_' . $start_callee_username;
	$redis->delete($key);

	$send_data = [
		'type' => 'refuse_video_invite',
		'who_refuse' => $start_callee_username,
	];
	$send_fd = getFdByGroupIdAndUsername($group_id, $start_caller_username);
	$server->push($send_fd, json_encode($send_data));
}

//start_callee不是火狐浏览器，共享start_caller摄像头失败
function not_firefox_reply($data, $server){
	$send_fd = getFdByGroupIdAndUsername($data['group_id'], $data['start_caller_username']);

	$send_data = [
		'type' => 'not_firefox_reply',
		'start_callee_username'=> $data['start_callee_username'],
	];
	$server->push($send_fd, json_encode($send_data));
}

//reply_caller同意视频邀请，也是火狐浏览器，但是开启摄像头失败
function is_firefox_but_error($data, $server){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$reply_caller_username = $data['reply_caller_username'];
	$reply_callee_username = $data['reply_callee_username'];

	$key = 'reply_video_group_' . $group_id . '_caller_' . $reply_caller_username . '_callee_' . $reply_callee_username;
	$redis->delete($key);

	$send_data = [
		'type' => 'is_firefox_but_error',
		'reply_caller_username' => $reply_caller_username,
		'error' => $data['error'],
	];
	$send_fd = getFdByGroupIdAndUsername($group_id, $reply_callee_username);
	$server->push($send_fd, json_encode($send_data));
}

//关闭视频通话
function close_video_chat($data, $server){
	$redis = getRedis();
	$role = $data['role'];
	$group_id = $data['group_id'];

	if($role == 'start_caller'){
		$start_caller_username = $data['start_caller_username'];

		$start_key = 'start_video_group_' . $group_id . '_caller_' . $start_caller_username . '_callee_*';
		$real_start_keys = $redis->keys($start_key);
		$real_start_key = $real_start_keys[0];

		$reply_key = 'reply_video_group_' . $group_id . '_caller_*' . '_callee_' . $start_caller_username;
		$real_reply_keys = $redis->keys($reply_key);
		$real_reply_key = !empty($real_reply_keys) ? $real_reply_keys[0] : '';

		$start_callee_info = $redis->hGet($real_start_key, 'callee');
		$start_callee_info = json_decode($start_callee_info, true);
		$start_callee_fd = $start_callee_info['fd'];

		$redis->delete($real_start_key, $real_reply_key);

		$send_data = [
			'type' => 'close_video_chat',
			'action' => 'start_caller_x_start_callee',
			'start_caller_username' => $start_caller_username,
		];
		$server->push($start_callee_fd, json_encode($send_data));
	}else if($role == 'reply_caller'){
		$reply_caller_username = $data['reply_caller_username'];

		$start_key = 'start_video_group_' . $group_id . '_caller_*' . '_callee_' . $reply_caller_username;
		$real_start_keys = $redis->keys($start_key);
		$real_start_key = !empty($real_start_keys) ? $real_start_keys[0] : '';

		$reply_key = 'reply_video_group_' . $group_id . '_caller_' . $reply_caller_username . '_callee_*';
		$real_reply_keys = $redis->keys($reply_key);
		$real_reply_key = $real_reply_keys[0];

		$reply_callee_info = $redis->hGet($real_reply_key, 'callee');
		$reply_callee_info = json_decode($reply_callee_info, true);
		$reply_callee_fd = $reply_callee_info['fd'];

		$redis->delete($real_start_key, $real_reply_key);

		$send_data = [
			'type' => 'close_video_chat',
			'action' => 'reply_caller_x_reply_callee',
			'reply_caller_username' => $reply_caller_username,
		];
		$server->push($reply_callee_fd, json_encode($send_data));
	}else if($role == 'start_callee'){
		$start_callee_username = $data['start_callee_username'];

		$start_key = 'start_video_group_' . $group_id . '_caller_*' . '_callee_' . $start_callee_username;
		$real_start_keys = $redis->keys($start_key);
		$real_start_key = $real_start_keys[0];

		$reply_key = 'reply_video_group_' . $group_id . '_caller_' . $start_callee_username . '_callee_*';
		$real_reply_keys = $redis->keys($reply_key);
		$real_reply_key = !empty($real_reply_keys) ? $real_reply_keys[0] : '';

		$start_caller_info = $redis->hGet($real_start_key, 'caller');
		$start_caller_info = json_decode($start_caller_info, true);
		$start_caller_fd = $start_caller_info['fd'];

		$redis->delete($real_start_key, $real_reply_key);

		$send_data = [
			'type' => 'close_video_chat',
			'action' => 'start_callee_x_start_caller',
			'start_callee_username' => $start_callee_username,
		];
		$server->push($start_caller_fd, json_encode($send_data));
	}
}

//start_caller调用摄像头失败，需要清除对应的信息
function clear_start_caller_info($data){
	$redis = getRedis();
	$group_id = $data['group_id'];
	$start_caller_username = $data['start_caller_username'];
	$start_callee_username = $data['start_callee_username'];

	$key = 'start_video_group_' . $group_id . '_caller_' . $start_caller_username . '_callee_' . $start_callee_username;
	$redis->delete($key);
}

//根据username删除关于与该username有关的video相关的key
function del_key_as_video($group_id, $username, $server){
	$redis = getRedis();
	$send_data = [
		'type' => 'close_video_chat',
		'action' => 'close_browser',
		'who_close' => $username,
	];
	$send_data = json_encode($send_data);

	$start_key1 = 'start_video_group_' . $group_id . '_caller_' . $username . '_callee_*';
	$start_key2 = 'start_video_group_' . $group_id . '_caller_*_callee_' . $username;
	$reply_key1 = 'reply_video_group_' . $group_id . '_caller_' . $username . '_callee_*';
	$reply_key2 = 'reply_video_group_' . $group_id . '_caller_*_callee_' . $username;

	$arr_keys = [$start_key1, $start_key2, $reply_key1, $reply_key2];

	$start_key1s = $redis->keys($start_key1);
	if(!empty($start_key1s)){ //start_caller
		$real_start_key1 = $start_key1s[0];
		
		$start_callee_info = $redis->hGet($real_start_key1, 'callee');
		$start_callee_info = json_decode($start_callee_info, true);
		$start_callee_fd = $start_callee_info['fd'];

		$server->push($start_callee_fd, $send_data);
		del_key_by_vague($arr_keys);
		return ;
	}

	$start_key2s = $redis->keys($start_key2);
	if(!empty($start_key2s)){ //start_callee
		$real_start_key2 = $start_key2s[0];

		$start_caller_info = $redis->hGet($real_start_key2, 'caller');
		$start_caller_info = json_decode($start_caller_info, true);
		$start_caller_fd = $start_caller_info['fd'];

		$server->push($start_caller_fd, $send_data);
		del_key_by_vague($arr_keys);
		return ;
	}

	$reply_key1s = $redis->keys($reply_key1);
	if(!empty($reply_key1s)){
		$real_reply_key1 = $reply_key1s[0];

		$reply_callee_info = $redis->hGet($real_reply_key1, 'callee');
		$reply_callee_info = json_decode($reply_callee_info, true);
		$reply_callee_fd = $reply_callee_info['fd'];

		$server->push($reply_callee_fd, $send_data);
		del_key_by_vague($arr_keys);
		return ;
	}

	$reply_key2s = $redis->keys($reply_key2);
	if(!empty($reply_key2s)){
		$real_reply_key2 = $reply_key2s[0];

		$reply_caller_info = $redis->hGet($real_reply_key2, 'caller');
		$reply_caller_info = json_decode($reply_caller_info, true);
		$reply_caller_fd = $reply_caller_info['fd'];

		$server->push($reply_caller_fd, $send_data);
		del_key_by_vague($arr_keys);
		return ;
	}

	//到这里说明关闭浏览器退出的该用户没有在视频通话，不做任何处理
}

//检测用户username是否已经正在视频聊天中
function checkUsernameIsInVideo($group_id, $username, $server=null, $fd=null, $is_send_msg=false){
	$redis = getRedis();
	$is_in = false;
	$send_data = [
		'type' => 'has_in_video',
		'username' => $username,
	];
	$send_data = json_encode($send_data);

	$key_arr = [
		'start_video_group_' . $group_id . '_caller_' . $username . '_callee_*',
		'start_video_group_' . $group_id . '_caller_*_callee_' . $username,
		'reply_video_group_' . $group_id . '_caller_' . $username . '_callee_*',
		'reply_video_group_' . $group_id . '_caller_*_callee_' . $username,
	];
	foreach($key_arr as $val){
		$tmp_val = $redis->keys($val);
		if(!empty($tmp_val)){
			$is_in = true;
			break;
		}
	}

	if($is_in){
		if($is_send_msg){
			$server->push($fd, $send_data);	
		}
		return true;
	}
	return false;
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

//模糊匹配删除key
function del_key_by_vague($key){
	$redis = getRedis();

	if(is_array($key)){
		$del_keys = [];
		foreach($key as $v_key){
			$tmp_keys = $redis->keys($v_key);
			$del_keys = array_merge($del_keys, $tmp_keys);
		}
		if(!empty($del_keys)){
			$redis->delete($del_keys);
		}
	}else{
		$keys = $redis->keys($key);
		if(!empty($keys)){
			$redis->delete($keys);
		}
	}
}














?>