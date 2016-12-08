<?php  

require './room/common.php';

if(!empty($_SERVER['PATH_INFO']) && strpos($_SERVER['PATH_INFO'], 'logout') !== false){
	session_unset();
	session_destroy();
}

if(!empty($_POST['username']) && !empty($_POST['password'])){ //进行登录操作
	$user_list = [ // 用户列表
		'gutao' => ['password' => 'gt123', 'uid' => 1],
		'liuyan' => ['password' => 'ly123', 'uid' => 2],
		'hangaoyu' => ['password' => 'hgy123', 'uid' => 3],
	];

	if(!empty($user_list[$_POST['username']])){ //存在用户
		$user_info = $user_list[$_POST['username']];
		
		if($user_info['password'] == $_POST['password']){ //登录成功
			$_SESSION['uid'] = $user_info['uid'];
			$_SESSION['username'] = $_POST['username'];
			$_SESSION['group_id'] = $_POST['group_id'];
		}else{
			echo '密码错误';die;
		}
	}else{
		echo '不存在的用户名';die;
	}
}

if(empty($_SESSION['uid']) || empty($_SESSION['username']) || empty($_SESSION['group_id'])){ // 未登录
	include './room/login.html';
	die;
}

//已经登录的
$uid = $_SESSION['uid'];
$username = $_SESSION['username'];
$group_id = $_SESSION['group_id'];

include './room/room.html';













?>