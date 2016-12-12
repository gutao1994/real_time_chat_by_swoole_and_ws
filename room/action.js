var iceServer = { // stun server
	"iceServers" : [
		{
			"url" : "stun:stunserver.org"
		},
		{
			"url": "turn:numb.viagenie.ca",
            "username": "webrtc@live.com",
            "credential": "muazkh"
		}
	]
};

//兼容处理
navigator.getUserMedia = navigator.getUserMedia || navigator.webkitGetUserMedia || navigator.mozGetUserMedia;
var PeerConnection = (window.PeerConnection || window.webkitPeerConnection00 || window.webkitRTCPeerConnection || window.mozRTCPeerConnection);
window.RTCSessionDescription = window.mozRTCSessionDescription || window.RTCSessionDescription;

//初始化peerconnection实例
var pc_start = null;
var pc_reply = null;

//初始化视频流对象
var stream_start = null;
var stream_reply = null;

/************************** 分割线 ******************************/

$(function (){
	var uid = $('.center :hidden[name=uid]').val().trim();
	var username = $('.center :hidden[name=username]').val().trim();
	var group_id = $('.center :hidden[name=group_id]').val().trim();

	var wsServer = 'ws://'+location.host+':9501';
	var ws = new WebSocket(wsServer);

	$('.center button').click(function (){
		var content = $(this).parent().children('textarea').val().trim();
		if(content == ''){
			alert('请输入内容');return false;
		}
		if(content.length >= 30){
			alert('输入的内容太多啦');return false;
		}

		var send_data = {
			type: 'msg',
			from_uid: uid,
			from_username: username,
			group_id: group_id,
			msg: content
		};

		ws.send(JSON.stringify(send_data));
		$(this).parent().children('textarea').val('');
	});

	/*-----------------------分割线-----------------------*/
	ws.onopen = function (e){
		var send_data = {
			type: 'login',
			uid: uid,
			username: username,
			group_id: group_id
		};
		ws.send(JSON.stringify(send_data));
	}

	ws.onmessage = function (e){
		var data = JSON.parse(e.data);
		var type = data.type;

		if(type == 'hasLogin'){ //已经登录了
			alert('您已经在其他的地方登录了该账号');
			$('.center button').prop('disabled', 'disabled');
			location.href = './room/sina/error.html';
			return false;
		}else if(type == 'someoneComing'){ //有用户进入该房间啦
			var username_some = data.username;
			makeUserList(username_some, false);
			send_sys_msg('用户 ' + username_some + ' 进入该房间啦');
		}else if(type == 'userlistForLogin'){ //在线用户列表
			var userlist = data.data;
			refresh_user_list(userlist, username);
			send_sys_msg('欢迎你进入当前聊天', false);
		}else if(type == 'first_one'){ //当前用户是该房间的第一位用户
			makeUserList(username, true);
			send_sys_msg('你是该房间的第一位用户哦', false);
		}else if(type == 'msg'){ //接收到普通消息
			send_sms_msg(username, data.from_username, data.msg, data.time, true);
		}else if(type == 'logout'){ //有用户退出
			var username_some = data.username;
			delUserList(username_some);
			send_sys_msg(username_some + '退出该房间啦', true);
		}else if(type == 'image'){ //接收到图片消息
			if('is_end' in data){ //收到分段的图片数据
				send_image_msg_split(username, data, true);
			}else{
				send_image_msg(username, data.from_username, data.dataUrl, data.time, true);
			}
		}else if(type == 'tuya'){ //逻辑同上
			if('is_end' in data){ //收到分段的涂鸦数据
				send_tuya_msg_split(username, data, true);
			}else{
				send_tuya_msg(username, data.from_username, data.dataUrl, data.time, true);
			}
		}else if(type == 'video_invite'){ //收到视频通话邀请信息
			var start_caller_username = data.start_caller_username;
			var conf = confirm(start_caller_username + ' 邀请你进行视频通话，是否答应?');

			if(conf){ //答应
				pc_start = new PeerConnection(iceServer);
				pc_start.onicecandidate = function(event){
				    if (event.candidate !== null) {
				    	var send_data = {
				    		'type': 'ice',
				    		'role': 'start_callee',
				    		'start_caller_username': start_caller_username,
				    		'start_callee_username': username,
				    		'group_id': group_id,
				    		"candidate": event.candidate
				    	};
				    	ws.send(JSON.stringify(send_data));
				    }
				};

				pc_start.setRemoteDescription(new RTCSessionDescription(data.sdp));
				pc_start.createAnswer(function (desc){
					pc_start.setLocalDescription(desc);
					var send_data = {
						'type': 'sdp',
						'role': 'start_callee_answer',
						'start_caller_username': start_caller_username,
						'start_callee_username': username,
						'group_id': group_id,
						'sdp': desc,
					};
					ws.send(JSON.stringify(send_data));
				}, function (error){
					alert('创建answer失败'); //一般不会失败
				});
				
				//在添加音视频流进来的时候
				pc_start.onaddstream = function (e){
					var stream_url = window.URL.createObjectURL(e.stream);
					$('#video_chat_layer_remote video').attr('src', stream_url).attr('autoplay', true);
					$('#video_chat_layer').show();
					$('#full_screen').show();

					//start_callee开始调用摄像头，尝试给start_caller发送视频数据
					if(getOs() != 'Firefox'){
						var send_data = {
							'type': 'not_firefox_reply',
							'start_caller_username': start_caller_username,
							'start_callee_username': username,
							'group_id': group_id
						};
						ws.send(JSON.stringify(send_data));
						alert('使用火狐浏览器你就能给对方共享自己的摄像头啦');
						return false;
					}

					pc_reply = new PeerConnection(iceServer);
					pc_reply.onicecandidate = function(event){
					    if (event.candidate !== null) { //caller发送ice
					    	var send_data = {
					    		'type': 'ice',
					    		'role': 'reply_caller',
					    		'reply_caller_username': username,
					    		'reply_callee_username': start_caller_username,
					    		'group_id': group_id,
					    		"candidate": event.candidate
					    	};
					    	ws.send(JSON.stringify(send_data));
					    }
					};

					navigator.getUserMedia({"audio": true, "video": true}, function (stream){ //捕捉音视频流
						stream_reply = stream;
						$('#video_chat_layer_local video').attr('src', URL.createObjectURL(stream)).attr('autoplay', true);
						pc_reply.addStream(stream);
						pc_reply.createOffer(function (desc){
							pc_reply.setLocalDescription(desc);

							var send_data = {
								'type': 'sdp',
								'role': 'reply_caller_offer',
								'reply_caller_username': username,
								'reply_callee_username': start_caller_username,
								'group_id': group_id,
								'sdp': desc
							};
							ws.send(JSON.stringify(send_data)); //caller 发送sdp
						}, function (error){
							alert('创建sdp失败...'); //一般不会失败...
						});
					}, function (error){ //获取用户设备失败
						pc_reply = null;
						var send_data = {
							'type': 'is_firefox_but_error',
							'reply_caller_username': username,
							'reply_callee_username': start_caller_username,
							'group_id': group_id,
							'error': error
						};
						ws.send(JSON.stringify(send_data));
						alert('出错啦: ' + error);
					});
				}
			}else{ //拒绝视频邀请
				var send_data = {
					'type': 'refuse_video_invite',
					'start_caller_username': start_caller_username,
					'start_callee_username': username,
					'group_id': group_id
				};
				ws.send(JSON.stringify(send_data));
			}
		}else if(type == 'ice_res'){ //收到ice信息
			if(data.role == 'start_caller' || data.role == 'start_callee'){
				pc_start.addIceCandidate(new RTCIceCandidate(data.candidate));

			}else if(data.role == 'reply_caller' || data.role == 'reply_callee'){
				pc_reply.addIceCandidate(new RTCIceCandidate(data.candidate));
			}
		}else if(type == 'return_start_callee_sdp'){ //接收到start_callee的sdp
			pc_start.setRemoteDescription(new RTCSessionDescription(data.sdp));
		}else if(type == 'return_reply_callee_sdp'){ //接收到reply_callee的sdp
			pc_reply.setRemoteDescription(new RTCSessionDescription(data.sdp));
		}else if(type == 'video_callback'){ //与视频邀请信息不一样，此时无需确认是否接收视频流
			var reply_caller_username = data.reply_caller_username;

			pc_reply = new PeerConnection(iceServer);
			pc_reply.onicecandidate = function(event){
			    if (event.candidate !== null) {
			    	var send_data = {
			    		'type': 'ice',
			    		'role': 'reply_callee',
			    		'reply_caller_username': reply_caller_username,
			    		'reply_callee_username': username,
			    		'group_id': group_id,
			    		"candidate": event.candidate
			    	};
			    	ws.send(JSON.stringify(send_data));
			    }
			};
			pc_reply.setRemoteDescription(new RTCSessionDescription(data.sdp));
			pc_reply.createAnswer(function (desc){
				pc_reply.setLocalDescription(desc);
				var send_data = {
					'type': 'sdp',
					'role': 'reply_callee_answer',
					'reply_caller_username': reply_caller_username,
					'reply_callee_username': username,
					'group_id': group_id,
					'sdp': desc,
				};
				ws.send(JSON.stringify(send_data));
			}, function (error){
				alert('创建answer失败'); //一般不会失败
			});
			pc_reply.onaddstream = function (e){
				var stream_url = window.URL.createObjectURL(e.stream);
				$('#video_chat_layer_remote video').attr('src', stream_url).attr('autoplay', true);
			};
		}else if(type == 'refuse_video_invite'){ //拒绝了视频通话邀请的信息
			pc_start = null;
			stream_start.getVideoTracks()[0].stop();
			stream_start.getAudioTracks()[0].stop();
			stream_start = null;

			$('#full_screen').hide();
			$('#video_chat_layer').hide();
			
			alert(data.who_refuse + '拒绝了您的视频通话邀请');
		}else if(type == 'not_firefox_reply'){ //start_callee不是火狐浏览器，共享摄像头给start_caller失败
			var start_callee_username = data.start_callee_username;
			alert(start_callee_username + '使用的不是火狐浏览器，因此你看不到对方，但是对方能看到你');
		}else if(type == 'is_firefox_but_error'){ //reply_caller同意视频邀请，也是火狐浏览器，但是开启摄像头失败
			var reply_caller_username = data.reply_caller_username;
			var error = data.error;
			alert(reply_caller_username + '开启摄像头失败，因此你看不到对方，但是对方能看到你' + ',error:' + error);
		}else if(type == 'close_video_chat'){ //关闭视频通话
			var action = data.action;
			$('#full_screen').hide();
			$('#video_chat_layer').hide();
			pc_start = null;
			pc_reply = null;

			if(action == 'start_caller_x_start_callee'){
				var start_caller_username = data.start_caller_username;
				
				if(stream_reply !== null){
					stream_reply.getVideoTracks()[0].stop();
					stream_reply.getAudioTracks()[0].stop();
				}
				stream_reply = null;

				alert(start_caller_username + '关闭了视频通话');
			}else if(action == 'reply_caller_x_reply_callee'){
				var reply_caller_username = data.reply_caller_username;

				if(stream_start !== null){
					stream_start.getVideoTracks()[0].stop();
					stream_start.getAudioTracks()[0].stop();
				}
				stream_start = null;

				alert(reply_caller_username + '关闭了视频通话');
			}else if(action == 'start_callee_x_start_caller'){
				var start_callee_username = data.start_callee_username;

				if(stream_start !== null){
					stream_start.getVideoTracks()[0].stop();
					stream_start.getAudioTracks()[0].stop();
				}
				stream_start = null;
				
				alert(start_callee_username + '关闭了视频通话');
			}else if(action == 'close_browser'){ //直接关闭了浏览器
				if(stream_start !== null){
					stream_start.getVideoTracks()[0].stop();
					stream_start.getAudioTracks()[0].stop();
				}
				stream_start = null;

				if(stream_reply !== null){
					stream_reply.getVideoTracks()[0].stop();
					stream_reply.getAudioTracks()[0].stop();
				}
				stream_reply = null;

				alert(data.who_close + '关闭了视频通话');
			}
		}else if(type == 'has_in_video'){
			$('#full_screen').hide();
			$('#video_chat_layer').hide();
			pc_start = null;
			pc_reply = null;
			if(stream_start !== null){
				stream_start.getVideoTracks()[0].stop();
				stream_start.getAudioTracks()[0].stop();
			}
			stream_start = null;
			alert(data.username + '正在视频通话中，请稍后再拨');
		}

		
	}

	ws.onclose = function (e){

	}

	ws.onerror = function (e){
		alert('连接出错啦，请检查你的网络');
	}

	//发送心跳包，服务端无需理会(10s)
	setInterval(function (){
		ws.send('ping');
	}, 10000);

	$('form').submit(function (e){return false;});

	/*发送图片*/
	$(':input.pic').change(function (){ //选择图片时的操作
		var file = this.files[0];
		var image_preg = /image.*/;
		var max_size = 2097152;

		if(!image_preg.test(file.type)){ //文件校验
			alert('只能选择图片哦');
			$(this).val('');
			return false;
		}
		if(file.size > max_size){
			alert('图片不建议超过2M');
			$(this).val('');
			return false;
		}

		var reader = new FileReader();

		reader.onload = function(e){
        	if(ws.readyState == 1){
        		var dataUrl = e.target.result;
	        	var send_data = {
	        		type: 'image',
	        		from_username: username,
	        		group_id: group_id,
	        		dataUrl: dataUrl
	        	};
	        	
	        	ws.send(JSON.stringify(send_data));
        	}else{
        		alert('稍等,应用正在初始化...');
        	}

        	$(':input.pic').val('');
        };

        reader.readAsDataURL(file);
	});
	/*发送图片*/

	/*涂鸦画布*/
	(function (){
		var mycanvas = $('#myCanvas');
		var ctx = mycanvas.get(0).getContext("2d");ctx.lineWidth = 1;
		var has_paint = false;
		var x = '';
		var y = '';
		$('span.send_tuya').click(function (){
			has_paint = false;
			x = '';
			y = '';
			ctx.clearRect(0,0,400,300);
			if($('#huabu').is(':visible')){
				$('#huabu').slideUp();
			}else{
				$('#huabu').slideDown();
			}
		});
		mycanvas.mousedown(function (e){
			var offset_x = mycanvas.offset().left;
			var offset_y = mycanvas.offset().top;

			x = e.clientX-2-offset_x;
		    y = e.clientY-2-offset_y;

		    mycanvas.mousemove(function (e){
		    	has_paint = true;
		    	ctx.save();
				ctx.beginPath();
				ctx.moveTo(x,y);
				ctx.lineTo(e.clientX-2-offset_x,e.clientY-2-offset_y);
				ctx.stroke();
				x = e.clientX-2-offset_x;
				y = e.clientY-2-offset_y;
				ctx.restore();
		    });
		});
		document.documentElement.onmouseup = function(e){
		    mycanvas.unbind('mousemove');
		}
		$('#huabu .repaint').click(function (){
			ctx.clearRect(0,0,400,300);
			x = '';
			y = '';
			has_paint = false;
		});
		$('#huabu .send_canvas').click(function (){
			if(has_paint == false){
				alert('还没有涂鸦呢');return false;
			}

			var tmp_png = mycanvas.get(0).toDataURL("image/png");
			var max_size = 2090000;
			if(tmp_png.length >= max_size){
				alert('画的太多啦');return false; //一般不太可能达到这个值
			}

			var send_data = {
				type: 'tuya',
				from_username: username,
				group_id: group_id,
				dataUrl: tmp_png
			};
			ws.send(JSON.stringify(send_data));
			$('span.send_tuya').trigger('click');
			layer.msg('发送涂鸦成功');
		});
	})();
	/*涂鸦画布*/

	/*点对点视频聊天*/
	$(document).delegate('span.video_chat', 'click', function (){ //点击按钮发起视频通话
		if(getOs() != 'Firefox'){
			alert('请在火狐浏览器下使用该功能');return false;
		}

		$('#full_screen').show();
		$('#video_chat_layer').show();
		var start_callee_username = $(this).parent().attr('username');

		pc_start = new PeerConnection(iceServer);
		pc_start.onicecandidate = function(event){
		    if (event.candidate !== null) { //caller发送ice
		    	var send_data = {
		    		'type': 'ice',
		    		'role': 'start_caller',
		    		'start_caller_username': username,
		    		'start_callee_username': start_callee_username,
		    		'group_id': group_id,
		    		"candidate": event.candidate
		    	};
		    	ws.send(JSON.stringify(send_data));
		    }
		};

		navigator.getUserMedia({"audio": true, "video": true}, function (stream){ //捕捉音视频流
			stream_start = stream;
			$('#video_chat_layer_local video').attr('src', URL.createObjectURL(stream)).attr('autoplay', true);
			pc_start.addStream(stream);
			pc_start.createOffer(function (desc){
				pc_start.setLocalDescription(desc);

				var send_data = {
					'type': 'sdp',
					'role': 'start_caller_offer',
					'start_caller_username': username,
					'start_callee_username': start_callee_username,
					'group_id': group_id,
					'sdp': desc
				};
				ws.send(JSON.stringify(send_data));
			}, function (error){
				alert('创建sdp失败...'); //一般不会失败...
			});
		}, function (error){ //获取用户设备失败
			$('#full_screen').hide();
			$('#video_chat_layer').hide();
			
			var send_data = {
				'type': 'clear_start_caller_info',
				'group_id': group_id,
				'start_caller_username': username,
		    	'start_callee_username': start_callee_username
			};
			ws.send(JSON.stringify(send_data));
			alert('出错啦: ' + error);
		});
	});

	$('.close_video_chat_layer').click(function (){ //关闭视频聊天按钮
		$('#full_screen').hide();
		$('#video_chat_layer').hide();
		pc_start = null;
		pc_reply = null;

		//关闭摄像头和麦克风
		if(stream_start !== null){ //说明该浏览器是start_caller
			stream_start.getVideoTracks()[0].stop();
			stream_start.getAudioTracks()[0].stop();
			stream_start = null;

			var send_data = {
				'type': 'close_video_chat',
				'role': 'start_caller',
				'group_id': group_id,
				'start_caller_username': username
			};
			ws.send(JSON.stringify(send_data));
			return false;
		}

		if(stream_reply !== null){ //说明该浏览器是reply_caller
			stream_reply.getVideoTracks()[0].stop();
			stream_reply.getAudioTracks()[0].stop();
			stream_reply = null;

			var send_data = {
				'type': 'close_video_chat',
				'role': 'reply_caller',
				'group_id': group_id,
				'reply_caller_username': username
			};
			ws.send(JSON.stringify(send_data));
			return false;
		}

		//说明该浏览器是start_callee
		var send_data = {
			'type': 'close_video_chat',
			'role': 'start_callee',
			'group_id': group_id,
			'start_callee_username': username
		};
		ws.send(JSON.stringify(send_data));
	});
	/*点对点视频聊天*/

});

/*-------------------------常用函数---------------------------*/
//会话框滚动条跑到最底部
function toBottom(){
	$('.content .left').scrollTop($('.content .left')[0].scrollHeight);
}

//添加用户
function makeUserList (username_some, is_myself){
	var cur_online_num = parseInt($('.cur_online_num').text().trim());
	cur_online_num++;
	$('.cur_online_num').text(cur_online_num);

	if(is_myself){
		var tmp_str = '<li username="' + username_some + '">'+username_some+'</li>';
	}else{
		var tmp_str = '<li username="' + username_some + '">'+username_some+'&nbsp;<span class="video_chat">视频聊天</span></li>';
	}
	
	$('p.online').next().append(tmp_str);
}

//删除用户
function delUserList (username_some){
	var cur_online_num = parseInt($('.cur_online_num').text().trim());
	cur_online_num--;
	$('.cur_online_num').text(cur_online_num);

	var lis = $('.right ul li');
	for(var i=0; i<lis.length; i++){
		if($(lis[i]).attr('username') == username_some){
			$(lis[i]).remove();
			return ;
		}
	}
}

//添加一条系统消息
function send_sys_msg (msg, to_bottom){
	var tmp_str = '<li>系统消息: '+msg+'</li>';
	$('.content .left ul').append(tmp_str);
	
	if(to_bottom){
		toBottom();
	}
}

//刷新用户列表
function refresh_user_list (userlist, cur_username){
	var ulength = userlist.length;
	$('.cur_online_num').text(ulength);

	var tmp_str = '';
	for(var i=0; i<ulength; i++){
		if(userlist[i] == cur_username){
			tmp_str += '<li username="' + userlist[i] + '">' + userlist[i] + '</li>';
		}else{
			tmp_str += '<li username="' + userlist[i] + '">' + userlist[i] + '&nbsp;<span class="video_chat">视频聊天</span></li>';
		}
	}
	$('p.online').next().empty().append(tmp_str);
}

//添加一条普通消息
function send_sms_msg(username, from_username, content, time, to_bottom){
	var li_con = '<li>';
	li_con += '<span class="username">';
	if(username == from_username){
		li_con += '你:</span>';
	}else{
		li_con += from_username+':</span>';
	}
	li_con += ' <span class="content">'+content+'</span>';
	li_con += '<span class="send_time">'+time+'</span>';
	li_con += '</li>';

	$('.content .left ul').append(li_con);
	$('.content .left ul li:last').parseEmotion();

	if(to_bottom){
		toBottom();
	}
}

//添加一条图片消息
function send_image_msg(username, from_username, dataUrl, time, to_bottom){ //与上面函数存在代码冗余,有空可以修复
	var li_con = '<li>';
	li_con += '<span class="username">';
	if(username == from_username){
		li_con += '你:</span>';
	}else{
		li_con += from_username+':</span>';
	}
	li_con += ' <span class="content">'
	li_con += 	'<img width="200px" height="28px" src="' + dataUrl + '" />&nbsp;';
	li_con += 	'<span class="showBigPic">查看大图</span>';
	li_con += '</span>';
	li_con += '<span class="send_time">'+time+'</span>';
	li_con += '</li>';

	$('.content .left ul').append(li_con);

	if(to_bottom){
		toBottom();
	}
}
//添加一条涂鸦消息
function send_tuya_msg(username, from_username, dataUrl, time, to_bottom){ //与上面函数存在代码冗余,有空可以修复
	var li_con = '<li>';
	li_con += '<span class="username">';
	if(username == from_username){
		li_con += '你:</span>';
	}else{
		li_con += from_username+':</span>';
	}
	li_con += ' <span class="content">'
	li_con += 	'<img width="200px" height="28px" src="' + dataUrl + '" />&nbsp;';
	li_con += 	'<span class="showTuya">查看涂鸦</span>';
	li_con += '</span>';
	li_con += '<span class="send_time">'+time+'</span>';
	li_con += '</li>';

	$('.content .left ul').append(li_con);

	if(to_bottom){
		toBottom();
	}
}

//处理分段的图片数据
var image_split = '';
function send_image_msg_split (username, data, to_bottom){
	if(data.is_end === false){ //数据还没有接收完毕
		image_split += data.dataUrl;
	}else{ //接收完毕
		image_split += data.dataUrl;
		var li_con = '<li>';
		li_con += '<span class="username">';
		if(username == data.from_username){
			li_con += '你:</span>';
		}else{
			li_con += data.from_username+':</span>';
		}
		li_con += ' <span class="content">'
		li_con += 	'<img width="200px" height="28px" src="' + image_split + '" />&nbsp;';
		li_con += 	'<span class="showBigPic">查看大图</span>';
		li_con += '</span>';
		li_con += '<span class="send_time">'+data.time+'</span>';
		li_con += '</li>';

		$('.content .left ul').append(li_con);

		if(to_bottom){
			toBottom();
		}

		image_split = '';
	}
}

//处理分段的涂鸦数据
var tuya_split = '';
function send_tuya_msg_split (username, data, to_bottom){
	if(data.is_end === false){ //数据还没有接收完毕
		tuya_split += data.dataUrl;
	}else{ //接收完毕
		tuya_split += data.dataUrl;
		var li_con = '<li>';
		li_con += '<span class="username">';
		if(username == data.from_username){
			li_con += '你:</span>';
		}else{
			li_con += data.from_username+':</span>';
		}
		li_con += ' <span class="content">'
		li_con += 	'<img width="200px" height="28px" src="' + tuya_split + '" />&nbsp;';
		li_con += 	'<span class="showTuya">查看涂鸦</span>';
		li_con += '</span>';
		li_con += '<span class="send_time">'+data.time+'</span>';
		li_con += '</li>';

		$('.content .left ul').append(li_con);

		if(to_bottom){
			toBottom();
		}

		tuya_split = '';
	}
}

//获取当前浏览器类型
function getOs(){      
	if(navigator.userAgent.indexOf("MSIE") > 0) {   
		return "MSIE";   
	}

	if(navigator.userAgent.indexOf("Firefox") > 0){   
		return "Firefox";   
	}

	if(navigator.userAgent.indexOf("Chrome") > 0) {   
		return "Chrome";   
	}

	if(navigator.userAgent.indexOf("Camino") > 0){   
		return "Camino";   
	}

	if(navigator.userAgent.indexOf("Gecko/") > 0){   
		return "Gecko";   
	}
}















