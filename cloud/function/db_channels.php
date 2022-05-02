<?php
setlocale(LC_ALL, "ru_RU.UTF-8");

	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем параметры
		$action = $_POST['action'];
		$user_id = (int) $_POST['user_id'];
		$session_id = $_POST['session_id'];
		
		# Если action пустой, то прерываем скрипт
		if (!empty($action) and !empty($user_id) and !empty($session_id)) {
			
			# Подключаем системные файл
			include_once 'db_func.php';
			
			# Получаем session_id пользователя
			$sth = $dbh->prepare('SELECT session_id, block FROM users WHERE id = ?');
			$sth->execute(array($user_id));

			# Получаем id сессии с базы данных
			$sessions = $sth->fetchAll(PDO::FETCH_ASSOC);
			$_session_id = $sessions[0]['session_id'];
			$_block = $sessions[0]['block'];
			
			# Если id сессий не совпадает, обрываем связь
			if ($_session_id !== $session_id or $_block == 1) {
				throw new Exception('Отказано в доступе');
			}
			
		} else {
			# Закрываем соединение с базой
			throw new Exception('Ошибка передачи данных');
		}
		
	} catch(Exception $e) {

		# Закрываем соединение с базой
		$dbh = null;

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);
		
		# Прерываем скрипт
		die();

	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Создание диалога с пользователем
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


switch ($action) {
case 'create_channel_type_dialog':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$friend_id = (int) $_POST['friend_id'];
		$secure = (int) $_POST['secure'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($friend_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if ($user_id == $friend_id) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		# Запрос к БД
		$sth = $dbh->prepare("SELECT *
							  FROM (
									   SELECT count(f.id) AS _cnt,
											  channel_id
										 FROM followers AS f
											  JOIN
											  channels AS c ON c.id = f.channel_id
										WHERE f.user_id IN (?, ?) AND 
											  c.type = 1
										GROUP BY f.channel_id
								   )
								   AS exist
							 WHERE exist._cnt = 2");
		$sth->execute(array($user_id, $friend_id));
		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		# ---------------------------------------------------------------------------------------
		
		# ---------------------------------------------------------------------------------------
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT u.write_me,
								   (
									   SELECT email
										 FROM users
										WHERE id = ?
								   )
								   AS email,
								   (
									   SELECT id
										 FROM friends
										WHERE user_id = u.id AND 
											  friend_id = ?
								   )
								   AS friend,
								   (
									   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
										 FROM users
										WHERE id = ?
								   )
								   AS display_name
							  FROM users AS u
							 WHERE u.id = ?;");
		$sth->execute(array($friend_id, $user_id, $user_id, $friend_id));
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		# ---------------------------------------------------------------------------------------
		
		if (($access[0]['write_me'] == 0) AND empty($access[0]['friend'])) {
			throw new Exception('Пользователь ограничил отправку сообщений');
		}
	
		# Проверяем на существование диалога
		if (count($exist) == 1) {
			
			# Запрос к БД
			$stmt = $dbh->prepare('UPDATE followers SET visible=? WHERE user_id=? AND channel_id=?');
			$stmt->execute(array(1, $user_id, $exist[0]['channel_id']));
			
			# Запрос к БД
			$stmt = $dbh->prepare('UPDATE followers SET visible=? WHERE user_id=? AND channel_id=?');
			$stmt->execute(array(1, $friend_id, $channel_id));
			
		} else {
			
			# Запрос к БД
			$stmt = $dbh->prepare('INSERT INTO channels (user_id, type, secure, date_create) VALUES (?, ?, ?, ?);');
			$stmt->execute(array($user_id, 1, $secure, $date_create));
			$channel_id = $dbh->lastInsertId();
			
			# Запрос к БД
			$stmt = $dbh->prepare('INSERT INTO followers (user_id, channel_id, date_follow) VALUES (?, ?, ?);');
			$stmt->execute(array($user_id, $channel_id, $date_create));
			
			# Запрос к БД
			$stmt = $dbh->prepare('INSERT INTO followers (user_id, channel_id, date_follow) VALUES (?, ?, ?);');
			$stmt->execute(array($friend_id, $channel_id, $date_create));
			
			# Отправляем письмо пользователю
			$mail = $access[0]['email'];
			$subject = "Simple Chat - Новый диалог";
			$display_name = $access[0]['display_name'];
			$message = " 
			<html> 
				<head> 
					<title>Новый диалог</title>				
				</head> 
				<body> 
					<p style='font-size: 13px!Important; width: 300px;'>
						<p><h3>У вас новый диалог с пользователем</h3></p>
						<p><b>$display_name</b></p>
						<p><span style='font-size: 13px!Important; color: #777;'>Зайдите в Ваш аккаунт Simple Chat, что-бы вступить в диалог</span>
					</p>
				</body> 
			</html>"; 
			$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <account@simple-chat.ru>\r\n";
			mail($mail, $subject, $message, $headers);
			
		}
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Создаем групповой чат
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'create_channel_type_chat':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$name = $_POST['name'];
		$invite_type = (int) $_POST['invite_type'];
		$secure = (int) $_POST['secure'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($name)) {
			throw new Exception('Вы оставили название пустым');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO channels (user_id, type, secure, invite_type, name, date_create) VALUES (?, ?, ?, ?, ?, ?);');
		$stmt->execute(array($user_id, 2, $secure, $invite_type, $name, $date_create));
		$channel_id = $dbh->lastInsertId();
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO followers (user_id, channel_id, date_follow) VALUES (?, ?, ?);');
		$stmt->execute(array($user_id, $channel_id, $date_create));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	

#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Создаем канал
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'create_channel_type_channel':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$name = $_POST['name'];
		$invite_type = (int) $_POST['invite_type'];
		$post_type = (int) $_POST['post_type'];
		$public_type = (int) $_POST['public_type'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($name)) {
			throw new Exception('Вы оставили название пустым');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT u.email
							  FROM users AS u
							 WHERE u.id = ?;");
		$sth->execute(array($user_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO channels (user_id, type, invite_type, post_type, public_type, name, date_create) VALUES (?, ?, ?, ?, ?, ?, ?);');
		$stmt->execute(array($user_id, 3, $invite_type, $post_type, $public_type, $name, $date_create));
		$channel_id = $dbh->lastInsertId();
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO followers (user_id, access, channel_id, date_follow) VALUES (?, ?, ?, ?);');
		$stmt->execute(array($user_id, 1, $channel_id, $date_create));
		
		# Отправляем письмо пользователю
		$mail = $access[0]['email'];
		$subject = "Simple Chat - Новый канал";
		$message = " 
		<html> 
			<head> 
				<title>Новый канал</title>				
			</head> 
			<body> 
				<p style='font-size: 13px!Important; width: 300px;'>
					<p><h3>Вы создали новый канал в Simple Chat</h3></p>
					<p><b>$name</b></p>
					<p><span style='font-size: 13px!Important; color: #777;'>Теперь Вы можете управлять своим каналом, постить записи, набирать подписчиков а так же наделять их привилегированными правами.</span></p>
					<p>Желаем Вам удачи!</p>
				</p>
			</body> 
		</html>"; 
		$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <account@simple-chat.ru>\r\n";
		mail($mail, $subject, $message, $headers);
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загрузка аватара
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'upload_avatar':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$avatar_id = gen_id();
		$avatar = $_FILES;
		
		# Устанавливаем значения shortAvatar
		$tmp_src = "../avatars/$avatar_id.tmp";
		$short_src = "../avatars/$avatar_id.jpg";
		$thumb_src = "../avatars/$avatar_id.thumb.jpg";

		# Проверка на id
		if (empty($channel_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Проверка на файлы
		if (empty($avatar)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT c.user_id,
									 c.type
							  FROM channels AS c
							 WHERE c.id = ?;");
		$sth->execute(array($channel_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if ($access[0]['user_id'] != $user_id and in_array($access[0]['type'], ['2','3'])) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------

		# Копируем изображение
		if($avatar['avatar']['error'] == 0) { 
		
			# Получаем MIME TYPE файла
			$type_img = array('image/jpeg','image/png');
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mtype = finfo_file($finfo, $avatar["avatar"]["tmp_name"]);
			finfo_close($finfo);
			
			# Перемещяем файл в дирректорию
			if (!rename($avatar["avatar"]["tmp_name"], $tmp_src)) {
				throw new Exception('Ошибка обработки данных');
			} else {
				# Пересоздаем оригинал изображения удаляя при этом все метаданные внутри файла
				if (in_array($mtype, $type_img)) {
					# Перезаписываем оригинал аватарки
					ImageThumbnail($tmp_src, $short_src, 200, $mtype);
					# Создаем миниатюру
					ImageThumbnail($tmp_src, $thumb_src, 50, $mtype);
				}
				
			}
			
			# Удаляем старый файл аватара
			$sth = $dbh->prepare('SELECT avatar FROM channels WHERE id=?');
			$sth->execute(array($channel_id));
			$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# Удаляем старые файлы
			unlink($tmp_src);
			if ($exist[0]['avatar'] != NULL) {
				$old_avatar = '../avatars/'.$exist[0]['avatar'].'.jpg';
				$old_avatar_thumb = '../avatars/'.$exist[0]['avatar'].'.thumb.jpg';
				unlink($old_avatar);
				unlink($old_avatar_thumb);
			}
			
			# Обновляем данные в БД
			$stmt = $dbh->prepare('UPDATE channels SET avatar=? WHERE id=?;');
			$stmt->execute(array($avatar_id, $channel_id));
			
		} else { 
			throw new Exception('Ошибка передачи данных');
		}
		
		# Выводим сообщение
		$message = [
					'status' => 'success', 
					'avatar' => $avatar_id
				   ];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загрузка списка каналов
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'channels_list':


	# Проверяем данные на соответствие
	try {
		
		# Загружаем список
		$sth = $dbh->prepare("SELECT c.*,
								   (
									   SELECT COUNT(id) 
										 FROM followers WHERE channel_id = c.id
								   )
								   AS followers
							  FROM channels AS c
							 WHERE c.type = 3 AND 
								   c.block = 0 AND 
								   c.public_type = 1;");
		$sth->execute();

		# Обрабатываем
		$data = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($data) > 0) {
			
			# Выводим сообщение
			$message = ['status' => 'success', 'data' => $data];
			echo json_encode($message, JSON_NUMERIC_CHECK);
			
		} else {
			
			# Выводим сообщение
			$message = ['status' => 'success', 'data' => false];
			echo json_encode($message, JSON_NUMERIC_CHECK);
			
		}
		
	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Подписываемся на канал
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'follow_channel':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$date_follow = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT f.*
							  FROM followers AS f
							 WHERE f.channel_id = ? AND 
								   f.user_id = ?;");
		$sth->execute(array($channel_id, $user_id));

		# Проверяем запись в БД
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($exist) == 1) {
			throw new Exception('Вы уже подписаны');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO followers (user_id, channel_id, date_follow) VALUES (?, ?, ?);');
		$stmt->execute(array($user_id, $channel_id, $date_follow));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Подписываем пользователя на канал
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'follow_user_channel':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$follower_id = (int) $_POST['follower_id'];
		$date_follow = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($follower_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id
							  FROM followers AS f
							 WHERE f.channel_id = ? AND 
								   f.user_id = ?;");
		$sth->execute(array($channel_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT f.*
							  FROM followers AS f
							 WHERE f.channel_id = ? AND 
								   f.user_id = ?;");
		$sth->execute(array($channel_id, $follower_id));

		# Проверяем запись в БД
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($exist) == 1) {
			throw new Exception('Пользователь уже подписан');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO followers (user_id, channel_id, date_follow) VALUES (?, ?, ?);');
		$stmt->execute(array($follower_id, $channel_id, $date_follow));
		
		# ---------------------------------------------------------------------------------------
		$sth = $dbh->prepare("SELECT (
									   SELECT name
										 FROM channels
										WHERE id = ?
								   )
								   AS channel_name,
								   (
									   SELECT email
										 FROM users
										WHERE id = ?
								   )
								   AS email,
								   (
									   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
										 FROM users
										WHERE id = u.id
								   )
								   AS display_name,
								   u.gender
							  FROM users AS u
							 WHERE u.id = ?;");
		$sth->execute(array($channel_id, $follower_id, $user_id));
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		# ---------------------------------------------------------------------------------------
		
		# Отправляем письмо пользователю
		$mail = $access[0]['email'];
		$subject = "Simple Chat - Приглашение";
		$channel_name = $access[0]['channel_name'];
		$display_name = $access[0]['display_name'];
		$declension = ($access[0]['gender'] ? "пригласил" : "пригласила");
		$message = " 
		<html> 
			<head> 
				<title>Вас пригласили в сообщество</title>				
			</head> 
			<body> 
				<p style='font-size: 13px!Important; width: 300px;'>
					<p><b>$display_name</b> $declension Вас в сообщество <b>$channel_name</b></p>
					<p><span style='font-size: 13px!Important; color: #777;'>Зайдите в Simple Chat для просмотра сообщества. Вы в любой момент сможете покинуть его.</span></p>
				</p>
			</body> 
		</html>"; 
		$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <account@simple-chat.ru>\r\n";
		mail($mail, $subject, $message, $headers);
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Меняем статус vip у подписчика
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'follower_access':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$follower_id = (int) $_POST['follower_id'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($follower_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT c.user_id
							  FROM channels AS c
							 WHERE c.id = ?;");
		$sth->execute(array($channel_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if ($access[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE followers SET access = (CASE WHEN access = 0 THEN 1 ELSE 0 END) WHERE user_id=? and channel_id=?;');
		$stmt->execute(array($follower_id, $channel_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загрузка списка подписчиков
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'followers_list':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$access = (int) $_POST['access'];
		
		# Проверяем значения
		if ($access) {
			$access = "AND access = 1";
		} else {
			$access = "";
		}

		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT U.id,
								   (CASE WHEN display_name THEN nickname ELSE (first_name || ' ' || last_name) END) AS display_name,
								   (
									   SELECT MAX(last_action) AS last_action
										 FROM (
												  SELECT date_login AS last_action
													FROM users
												   WHERE id = u.id
												  UNION
												  SELECT MAX(date_create) AS last_action
													FROM posts
												   WHERE user_id = u.id
												  UNION
												  SELECT MAX(date_create) AS last_action
													FROM likes
												   WHERE user_id = u.id
												  UNION
												  SELECT MAX(date_create) AS last_action
													FROM comments
												   WHERE user_id = u.id
											  )
								   )
								   AS last_action,
								   (
									   SELECT id
										 FROM friends
										WHERE user_id = ? AND 
											  friend_id = f.user_id
								   )
								   AS friend,
								   f.channel_id,
								   U.avatar,
								   U.gender,
								   f.access
							  FROM followers AS f
								   JOIN
								   users AS u ON u.id = f.user_id
							 WHERE U.block = 0 AND 
								   f.channel_id = ? $access;");
		$sth->execute(array($user_id, $channel_id));

		# Обрабатываем
		$data = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($data) > 0) {
			
			# Выводим сообщение
			$message = ['status' => 'success', 'data' => $data];
			echo json_encode($message, JSON_NUMERIC_CHECK);
			
		} else {
			
			# Выводим сообщение
			$message = ['status' => 'success', 'data' => false];
			echo json_encode($message, JSON_NUMERIC_CHECK);
			
		}
		
	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Поиск подписчиков
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'search_followers':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$str = $_POST['str'];
		
		# Проверка данных
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверка данных
		if (!empty($str)) {
			$str = "%".$str."%";
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT u.id,
								   (CASE WHEN display_name THEN nickname ELSE (first_name || ' ' || last_name) END) AS display_name,
								   (
									   SELECT MAX(last_action) AS last_action
										 FROM (
												  SELECT date_login AS last_action
													FROM users
												   WHERE id = u.id
												  UNION
												  SELECT MAX(date_create) AS last_action
													FROM posts
												   WHERE user_id = u.id
												  UNION
												  SELECT MAX(date_create) AS last_action
													FROM likes
												   WHERE user_id = u.id
												  UNION
												  SELECT MAX(date_create) AS last_action
													FROM comments
												   WHERE user_id = u.id
											  )
								   )
								   AS last_action,
								   (
									   SELECT id
										 FROM friends
										WHERE user_id = ? AND 
											  friend_id = f.user_id
								   )
								   AS friend,
								   f.channel_id,
								   u.avatar,
								   u.gender,
								   f.access
							  FROM followers AS f
								   JOIN
								   users AS u ON u.id = f.user_id
							 WHERE U.block = 0 AND 
								   f.channel_id = ? AND 
								   (u.first_name LIKE ?) OR 
								   (u.last_name LIKE ?) OR 
								   (u.nickname LIKE ?) 
							 GROUP BY f.user_id;");
		$sth->execute(array($user_id, $channel_id, $str, $str, $str));

		# Обрабатываем
		$data = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($data) > 0) {
			
			# Выводим сообщение
			$message = ['status' => 'success', 'data' => $data];
			echo json_encode($message, JSON_NUMERIC_CHECK);
			
		} else {
			
			# Выводим сообщение
			$message = ['status' => 'success', 'data' => false];
			echo json_encode($message, JSON_NUMERIC_CHECK);
			
		}
		
	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Список файлов изображений
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'get_images_list':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id
							  FROM followers AS f
							 WHERE f.channel_id = ? AND 
								   f.user_id = ?;");
		$sth->execute(array($channel_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT A.*,
								   (
									   SELECT type
										 FROM channels
										WHERE id = ?
								   )
								   AS channel_type,
								   (
									   SELECT old_post
										 FROM channels
										WHERE id = ?
								   )
								   AS old_posts,
								   (
									   SELECT date_follow
										 FROM followers
										WHERE channel_id = ?
								   )
								   AS date_follow,
								   (
									   SELECT access
										 FROM followers AS F
										WHERE F.user_id = ? AND 
											  F.channel_id = ?
								   )
								   AS user_access
							  FROM attaches AS A
								   JOIN
								   posts AS P ON P.id = A.post_id
							 WHERE P.channel_id = ? AND 
								   A.mime LIKE '%image%' AND 
								   (CASE WHEN old_posts THEN P.date_create BETWEEN date_follow AND datetime('now', 'localtime') END) AND 
								   (CASE WHEN user_access IS 0 THEN P.access = 0 ELSE P.access END);");
		$sth->execute(array($channel_id, $channel_id, $channel_id, $user_id, $channel_id, $channel_id));
		
		# Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		$message = ['status' => 'success', 'data' => $result];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Список файлов
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'get_files_list':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id
							  FROM followers AS f
							 WHERE f.channel_id = ? AND 
								   f.user_id = ?;");
		$sth->execute(array($channel_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT A.*,
								   (
									   SELECT type
										 FROM channels
										WHERE id = ?
								   )
								   AS channel_type,
								   (
									   SELECT old_post
										 FROM channels
										WHERE id = ?
								   )
								   AS old_posts,
								   (
									   SELECT date_follow
										 FROM followers
										WHERE channel_id = ?
								   )
								   AS date_follow,
								   (
									   SELECT access
										 FROM followers AS F
										WHERE F.user_id = ? AND 
											  F.channel_id = ?
								   )
								   AS user_access
							  FROM attaches AS A
								   JOIN
								   posts AS P ON P.id = A.post_id
							 WHERE P.channel_id = ? AND 
								   A.mime NOT LIKE '%image%' AND 
								   (CASE WHEN old_posts THEN P.date_create BETWEEN date_follow AND datetime('now', 'localtime') END) AND 
								   (CASE WHEN user_access IS 0 THEN P.access = 0 ELSE P.access END);");
		$sth->execute(array($channel_id, $channel_id, $channel_id, $user_id, $channel_id, $channel_id));
		
		# Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		$message = ['status' => 'success', 'data' => $result];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удаление диалога
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_dialog':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		
		# Устанавливаем значения
		$remove_all = (int) $_POST['remove_all'];

		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.*
							  FROM followers AS f
							 WHERE f.channel_id = ? AND 
								   f.user_id = ?;");
		$sth->execute(array($channel_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) != 1) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Если удалить у всех
		if ($remove_all) {
			
			# Запрос к БД
			$stmt = $dbh->prepare('DELETE FROM channels WHERE id=?;');
			$stmt->execute(array($channel_id));
			
		} else {
			
			# Запрос к БД
			$stmt = $dbh->prepare('UPDATE followers SET access_id = view_id, visible=? WHERE user_id=? AND channel_id=?');
			$stmt->execute(array(0, $user_id, $channel_id));
			
		}

		

		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удаление канала
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_channel':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$password = $_POST['password'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($password)) {
			throw new Exception('Вы оставили поле пароль пустым');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT c.user_id
							  FROM channels AS c
							 WHERE c.id = ?;");
		$sth->execute(array($channel_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if ($access[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT id, password, salt FROM users WHERE id=?;");
		$sth->execute(array($user_id));
					
		# Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			foreach ($result as $row) {

				# Генерируем хэш пароля из соли и сверяем данные
				$hash_password = hash('sha256', $password . $row['salt']);
				
				# Сверяем хеш пароля
				if ($hash_password != $row['password']) {
					throw new Exception('Пароль не совпадает');
				}
			}
			
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM channels WHERE id=?;');
		$stmt->execute(array($channel_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Передача прав на управление каналом
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'transfer_channel':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$password = $_POST['password'];
		$new_user_admin = (int) $_POST['new_user_admin'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($password)) {
			throw new Exception('Вы оставили поле пароль пустым');
		}
		
		# Проверяем значения
		if (empty($new_user_admin)) {
			throw new Exception('Вы не выбрали пользователя');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT c.user_id
							  FROM channels AS c
							 WHERE c.id = ?;");
		$sth->execute(array($channel_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if ($access[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT id, password, salt FROM users WHERE id=?;");
		$sth->execute(array($user_id));
					
		# Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			foreach ($result as $row) {

				# Генерируем хэш пароля из соли и сверяем данные
				$hash_password = hash('sha256', $password . $row['salt']);
				
				# Сверяем хеш пароля
				if ($hash_password != $row['password']) {
					throw new Exception('Пароль не совпадает');
				}
			}
			
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE channels SET user_id=? WHERE id=?;');
		$stmt->execute(array($new_user_admin, $channel_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Отписываемся от канала
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'unfollow_channel':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM followers WHERE user_id=? AND channel_id=?;');
		$stmt->execute(array($user_id, $channel_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Отписываемся от чата
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'unfollow_chat':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM followers WHERE user_id=? AND channel_id=?;');
		$stmt->execute(array($user_id, $channel_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Получаем настройки канала
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'channel_get_settings':


	# Проверяем данные на соответствие
	try {

		$channel_id = (int) $_POST['channel_id'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT c.user_id
							  FROM channels AS c
							 WHERE c.id = ?;");
		$sth->execute(array($channel_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if ($access[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT * FROM channels WHERE id=?;");
		$sth->execute(array($channel_id));
		$data = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Выводим сообщение
		$message = ['status' => 'success', 'data' => $data];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Сохранение настроек канала
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'channel_save_settings':


	# Проверяем данные на соответствие
	try {

		$channel_id = (int) $_POST['channel_id'];
		$name = $_POST['name'];
		$description = $_POST['description'];
		$invite_type = (int) $_POST['invite_type'];
		$post_type = (int) $_POST['post_type'];
		$public_type = (int) $_POST['public_type'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($name)) {
			throw new Exception('Вы оставили название пустым');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT c.user_id
							  FROM channels AS c
							 WHERE c.id = ?;");
		$sth->execute(array($channel_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if ($access[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE channels SET name=?, description=?, invite_type=?, post_type=?, public_type=? WHERE id=?;');
		$stmt->execute(array($name, $description, $invite_type, $post_type, $public_type, $channel_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Сохранение настроек чата
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'chat_save_settings':


	# Проверяем данные на соответствие
	try {

		$channel_id = (int) $_POST['channel_id'];
		$name = $_POST['name'];
		$description = $_POST['description'];
		$invite_type = (int) $_POST['invite_type'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($name)) {
			throw new Exception('Вы оставили название пустым');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT c.user_id
							  FROM channels AS c
							 WHERE c.id = ?;");
		$sth->execute(array($channel_id));
		
		# Обрабатываем данные
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if ($access[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE channels SET name=?, description=?, invite_type=? WHERE id=?;');
		$stmt->execute(array($name, $description, $invite_type, $channel_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	

default:
break;


}


	# Закрываем соединение с базой
	$dbh = null;
	die();
	

?>