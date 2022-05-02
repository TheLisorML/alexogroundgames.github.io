<?php
setlocale(LC_ALL, "ru_RU.UTF-8");

	# Проверяем данные на соответствие
	try {

		# Устанавливаем параметры
		$action = $_POST['action'];
		$user_id = (int) $_POST['user_id'];
		$session_id = $_POST['session_id'];
		
		# Подключаем системные файл
		include_once 'db_func.php';
		
		# Если action пустой, то прерываем скрипт
		if (!empty($action) and !empty($user_id) and !empty($session_id)) {
			
			# Получаем данные с базы данных
			$sth = $dbh->prepare('SELECT session_id,
										   block,
										   (
											   SELECT COUNT(id)
												 FROM _server
										   )
										   AS server,
										   (
											   SELECT passkey
												 FROM _server WHERE id = 1
										   )
										   AS passkey
									  FROM users
									 WHERE id = ?;');
			$sth->execute(array($user_id));

			# Получаем данные с базы данных
			$session = $sth->fetchAll(PDO::FETCH_ASSOC);
			$_session_id = $session[0]['session_id'];
			$_block = $session[0]['block'];
			$_server = $session[0]['server'];
			$_passkey = $session[0]['passkey'];
			
			# Если сервер не настроен
			if (empty($_server)) {
				throw new Exception('Сервер не настроен');
			}
			
			# Если id сессий не совпадает, обрываем связь
			if ($_session_id !== $session_id or $_block == 1) {
				throw new Exception('Отказано в доступе');
			}
			
		} else {
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
# Сохраняем настройки
#---------------------------------------------------------------------------------------------------------------------------------------------------------------

	
switch ($action) {
case 'save_settings':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$name = $_POST['name'];
		$description = $_POST['description'];
		$passkey = $_POST['passkey'];
		$email = $_POST['email'];
		$on_dialog = (int) $_POST['on_dialog'];
		$on_secret_dialog = (int) $_POST['on_secret_dialog'];
		$on_group_chat = (int) $_POST['on_group_chat'];
		$on_channel = (int) $_POST['on_channel'];
		$on_vip_followers = (int) $_POST['on_vip_followers'];
		$on_likes = (int) $_POST['on_likes'];
		$on_comments = (int) $_POST['on_comments'];
		$on_favorites = (int) $_POST['on_favorites'];
		$date_updated = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($name)) {
			throw new Exception('Введите имя сервера');
		}
		
		# Проверяем значения
		if (empty($email)) {
			throw new Exception('Введите адрес почты');
		}
			
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE _server
		   SET name = ?,
			   description = ?,
			   email = ?,
			   passkey = ?,
			   on_dialog = ?,
			   on_secret_dialog = ?,
			   on_group_chat = ?,
			   on_channel = ?,
			   on_vip_followers = ?,
			   on_likes = ?,
			   on_comments = ?,
			   on_favorites = ?,
			   date_updated = ?
		 WHERE id = ?;'
		);
		$stmt->execute(array(
			$name, 
			$description,
			$email,
			$passkey,
			$on_dialog, 
			$on_secret_dialog, 
			$on_group_chat, 
			$on_channel, 
			$on_vip_followers, 
			$on_likes, 
			$on_comments, 
			$on_favorites, 
			$date_updated,
			1
		));
		
		#------------------------------------------------------------
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загрузка настроек сервера
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'get_settings':

	# Проверяем данные на соответствие
	try {

		# Запрос к БД для проверки первого запуска
		$sth = $dbh->prepare("SELECT s.*,
								   (
									   SELECT name
										 FROM channels
										WHERE id = s.channel_id
								   )
								   AS channel_name,
								   (
									   SELECT avatar
										 FROM channels
										WHERE id = s.channel_id
								   )
								   AS channel_avatar
							  FROM _server AS s
							 WHERE s.id = 1;");
		$sth->execute();

		# Обрабатываем данные
		$settings = $sth->fetchAll(PDO::FETCH_ASSOC);
	
		# Выводим сообщение
		$message = ['status' => 'success', 'data' => $settings[0]];
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
		$avatar_id = gen_id();
		$avatar = $_FILES;
		
		# Устанавливаем значения shortAvatar
		$tmp_src = "../avatars/$avatar_id.tmp";
		$short_src = "../avatars/$avatar_id.jpg";
		$thumb_src = "../avatars/$avatar_id.thumb.jpg";
		
		# Проверка на файлы
		if (empty($avatar)) {
			throw new Exception('Ошибка передачи данных');
		}

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
			
			# Запрос к БД
			$sth = $dbh->prepare('SELECT avatar FROM _server WHERE id=1');
			$sth->execute();
			$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# Удаляем старые файлы
			unlink($tmp_src);
			if ($exist[0]['avatar'] != NULL) {
				$old_avatar = '../avatars/'.$exist[0]['avatar'].'.jpg';
				$old_avatar_thumb = '../avatars/'.$exist[0]['avatar'].'.thumb.jpg';
				unlink($old_avatar);
				unlink($old_avatar_thumb);
			}
			
			# Запрос к БД
			$stmt = $dbh->prepare('UPDATE _server SET avatar=? WHERE id=1;');
			$stmt->execute(array($avatar_id));
			
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
# Установка канала новостей
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'set_channel_server':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT user_id, type FROM channels WHERE id = ?;");
		$sth->execute(array($channel_id));

		# Обрабатываем
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Если не администратор канала
		if ($exist[0]['user_id'] != $user_id) {
			throw new Exception('Вы не являетесь администратором');
		}
		
		# Если тип != канал
		if ($exist[0]['type'] != 3) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE _server SET channel_id = ? WHERE id = 1;');
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
# Сброс канала новостей
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'reset_channel_server':


	# Проверяем данные на соответствие
	try {
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE _server SET channel_id = NULL WHERE id = 1;');
		$stmt->execute();
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загрузка списка модераторов
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'moderators_list':


	# Проверяем данные на соответствие
	try {
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT u.id,
								   (CASE WHEN u.display_name THEN u.nickname ELSE (u.first_name || ' ' || u.last_name) END) AS display_name,
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
								   u.avatar,
								   u.gender,
								   a.c_users,
								   a.c_channels,
								   a.c_posts,
								   a.c_comments,
								   a.type,
								   a.id AS moderator_id
							  FROM _admins AS a
								   JOIN
								   users AS u ON u.id = a.user_id
							 WHERE u.block = 0 AND 
								   a.type = 2;");
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
# Поиск модераторов
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'search_moderators':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$str = $_POST['str'];
		
		# Проверка данных
		if (!empty($str)) {
			$str = "%".$str."%";
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT u.id,
								   (CASE WHEN u.display_name THEN u.nickname ELSE (u.first_name || ' ' || u.last_name) END) AS display_name,
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
								   u.avatar,
								   u.gender,
								   a.c_users,
								   a.c_channels,
								   a.c_posts,
								   a.c_comments,
								   a.type,
								   a.id AS moderator_id
							  FROM _admins AS a
								   JOIN
								   users AS u ON u.id = a.user_id
							 WHERE a.type = 2 AND 
								   u.block = 0 AND 
								   (u.first_name LIKE ?) OR 
								   (u.last_name LIKE ?) OR 
								   (u.nickname LIKE ?);");
		$sth->execute(array($str, $str, $str));

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
# Добавляем модератора
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'add_moderator':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$moderator_id = (int) $_POST['moderator_id'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($moderator_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT a.user_id FROM _admins AS a WHERE a.user_id = ?;");
		$sth->execute(array($moderator_id));

		# Проверяем запись в БД
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($exist) == 1) {
			throw new Exception('Пользователь уже является модератором');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO _admins (user_id, date_create) VALUES (?, ?);');
		$stmt->execute(array($moderator_id, $date_create));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Получение прав доступа модератора
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'moderator_get_settings':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$moderator_id = (int) $_POST['moderator_id'];
		$c_users = (int) $_POST['c_users'];
        $c_channels = (int) $_POST['c_channels'];
        $c_posts = (int) $_POST['c_posts'];
        $c_comments = (int) $_POST['c_comments'];
		
		# Проверяем значения
		if (empty($moderator_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT * FROM _admins AS a WHERE a.id = ?;");
		$sth->execute(array($moderator_id));
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
# Настройка прав доступа модератора
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'moderator_save_settings':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$moderator_id = (int) $_POST['moderator_id'];
		$c_users = (int) $_POST['c_users'];
        $c_channels = (int) $_POST['c_channels'];
        $c_posts = (int) $_POST['c_posts'];
        $c_comments = (int) $_POST['c_comments'];
		
		# Проверяем значения
		if (empty($moderator_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE _admins
								   SET c_users = ?,
									   c_channels = ?,
									   c_posts = ?,
									   c_comments = ?
								 WHERE id = ?;');
		$stmt->execute(array($c_users, $c_channels, $c_posts, $c_comments, $moderator_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удаление модератора
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'remove_moderator':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$moderator_id = (int) $_POST['moderator_id'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($moderator_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM _admins WHERE id=?;');
		$stmt->execute(array($moderator_id));
		
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