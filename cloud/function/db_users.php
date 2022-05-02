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
			$sth = $dbh->prepare('SELECT session_id, block FROM users WHERE id = ?;');
			$sth->execute(array($user_id));

			# Получаем данные с базы данных
			$session = $sth->fetchAll(PDO::FETCH_ASSOC);
			$_session_id = $session[0]['session_id'];
			$_block = $session[0]['block'];
			
			# Если id сессий не совпадает, обрываем связь
			if ($_session_id !== $session_id or $_block == 1) {
				throw new Exception('Отказано в доступе');
			}
			
		} else {
			
			# Проверяем параметр action, если он login, то не завершаем работу скрипта
			if ($action == "login" or $action == "create_user" or $action == "create_reset_code" or $action == "reset_password" or $action == "confirm_email") {
			} else {
				throw new Exception('Ошибка передачи данных');
			}
			
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
# Авторизация пользователя
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


switch ($action) {
case 'login':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$login = $_POST['login'];
		$password = $_POST['password'];
	
		# Проверка значений
		if (empty($login)) {
			throw new Exception('Введите логин или e-mail');
		}
		if (empty($password)) {
			throw new Exception('Введите пароль');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT u.*,
								   (CASE WHEN u.display_name == 0 THEN (u.first_name || ' ' || u.last_name) ELSE u.nickname END) AS display_name
							  FROM users AS u
							 WHERE u.email = ? OR 
								   u.nickname = ?;");
		$sth->execute(array($login, $login));
					
		# Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			foreach ($result as $row) {

				# Генерируем хэш пароля из соли и сверяем данные
				$hash_password = hash('sha256', $password . $row['salt']);
				
				# Сверяем почту
				if($login != $row['email'] and $login != $row['nickname']){
					throw new Exception('Логин или E-mail не совпадает');
				}
				
				# Сверяем хеш пароля
				if ($hash_password != $row['password']) {
					throw new Exception('Пароль не совпадает');
				}

				# Сверяем на блокировку пользователя
				if ($row['block'] == 1) {
					
					# Сбрасываем старую сессию от возможности действовать под старой.
					$_session_id = gen_id();
					$stmt = $dbh->prepare('UPDATE users SET session_id=? WHERE id=?');
					$stmt->execute(array($_session_id, $row['id']));
					throw new Exception('Аккаунт заблокирован');
					
				}

				# Обновляем соль и хэш пароля
				$id = $row['id'];
				$newsalt = microtime();
				$newpass = hash('sha256', $password . $newsalt);
				$date_login = date("Y-m-d H:i:s");
				$display_name = $row['display_name'];
				$display_date = date("d.m.Y в H:i");
				$session_id = gen_id();
				$stmt = $dbh->prepare('UPDATE users SET password=?, salt=?, date_login=?, session_id=? WHERE id=?');
				$stmt->execute(array($newpass, $newsalt, $date_login, $session_id, $id));
				
				# ----------------------------

				# Параметры пользователя
				$session_user = [
					'status' => 'success',
					'id' => $row['id'],
					'display_name' => $row['display_name'],
					'nickname' => $row['nickname'],
					'avatar' => $row['avatar'],
					'session_id' => $session_id,
					'parameters' => server_parameters
				];
				
				# ----------------------------
				
				# Отправляем письмо пользователю
				$mail = $row['email'];
				$server_email = server_parameters['mail'];
				$server_name = server_parameters['name'];
				$subject = "$server_name - В Ваш аккаунт выполнен вход"; 
				$message = " 
				<html> 
					<head> 
						<title>Уведомление безопасности</title>				
					</head> 
					<body> 
						<p style='font-size: 13px!Important; width: 300px;'>
							<p><h3>Уведомление безопасности</h3></p>
							<p><b>$display_name</b></p>
							<p><span style='font-size: 13px!Important; color: #777;'>Вы получили это письмо, потому что в Ваш аккаунт $server_name $display_date был выполнен вход.</span><br>
							<span style='font-size: 13px!Important; color: #777;'>Если это произошло без Вашего ведома, срочно зайдите в свой профиль, чтобы защитить свой аккаунт.</span></p>
						</p>
					</body> 
				</html>"; 
				$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: $server_name <$server_email>\r\n";
				mail($mail, $subject, $message, $headers);

				# Выводим сообщение
				echo json_encode($session_user);

			}
		} else {
			throw new Exception('Пользователь не найден');
		}

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	

#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Регистрация пользователя
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'create_user':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$password = $_POST['password'];
		$salt = $_POST['salt'];
		$first_name = $_POST['first_name'];
		$last_name = $_POST['last_name'];
		$email = $_POST['email'];
		$gender = (int) $_POST['gender'];
		
		# Проверка строки на фхождения
		$preg = "/^[a-zа-яё]{1}[a-zа-яё]*[a-zа-яё]{1}$/iu";
		$preg_alnum = "/^[a-zа-яё\d]{1}[a-zа-яё\d]*[a-zа-яё\d]{1}$/iu";
		
		# Проверка на Имя
		if (empty($first_name) or !preg_match($preg, $first_name)) {
			throw new Exception('Вы не указали имя');
		} elseif (mb_strlen($first_name, 'utf-8') < 2) {
			throw new Exception('Имя не может быть менее 2 символов');
		}
		
		# Проверка на Фамилию
		if (empty($last_name) or !preg_match($preg, $last_name)) {
			throw new Exception('Вы не указали фамилию');
		} elseif (mb_strlen($last_name, 'utf-8') < 2) {
			throw new Exception('Фамилия не может быть менее 2 символов');
		}

		# Проверка на почту
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Вы указали неверный e-mail');
		}

		# Подготавливаем запрос в базу данных
		$sth = $dbh->prepare('SELECT id, reg_code FROM users WHERE email = ?;');
		$sth->execute(array($email));

		# Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) > 0) {
			$exists = $result[0]['reg_code'];
			$user_id = $result[0]['id'];
			if (empty($exists)) {
				throw new Exception('Такой пользователь существует');
			}
		}
		
		# Создаем код подтверждения почты
		$reg_code = gen_code();
		
		# Устанавливаем значения
		$date_create = date("Y-m-d H:i:s");
		
		# Если запись существует то, обновляем ее
		if (!empty($exists)) {
			$stmt = $dbh->prepare('UPDATE users SET password=?, salt=?, first_name=?, last_name=?, reg_code=? WHERE id=?;');
			$stmt->execute(array($password, $salt, $first_name, $last_name, $reg_code, $user_id));			
		} else {
			$stmt = $dbh->prepare('INSERT INTO users (email, password, salt, first_name, last_name, date_login, date_create, avatar, gender, block, reg_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);');
			$stmt->execute(array($email, $password, $salt, $first_name, $last_name, $date_create, $date_create, null, $gender, 1, $reg_code));
		}
			
		# Отправляем письмо пользователю
		$server_email = server_parameters['mail'];
		$server_name = server_parameters['name'];
		$subject = "$server_name - Подтверждение аккаунта"; 
		$message = ' 
		<html> 
			<head> 
				<title>Код подтверждения почты</title>				
			</head> 
			<body> 
				<p style="font-size: 13px!Important;">
					<b>Код подтверждения аккаунта - $server_name</b> <br>
					<span style="font-size: 13px!Important; color: #777;">Введите данный код в окно программы для подтверждения Вашего аккаунта</span>
				</p>
				<p style="font-size: 13px!Important;border-color:#e6e6e6;border-width:1px;border-style:solid;background-color:#f5f5f5;padding-top:15px;padding-right:15px;padding-bottom:15px;padding-left:15px;">'.$reg_code.'</p>
			</body> 
		</html>'; 
		$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: $server_name <$server_email>\r\n";
		mail($email, $subject, $message, $headers);
			
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
# Удалить аккаунт
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_user':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$password = $_POST['password'];
		
		# Проверяем значения
		if (empty($password)) {
			throw new Exception('Вы оставили поле пароль пустым');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT * FROM users WHERE id=?;");
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
				
				# Запрос к БД
				$stmt = $dbh->prepare('DELETE FROM users WHERE id=?;');
				$stmt->execute(array($user_id));
				
			}
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
# Подтверждаем почту пользователя
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'confirm_email':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$email = $_POST['email'];
		$code = $_POST['code'];
	
		# Проверка на id
		if (empty($email)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Проверка на почту
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Вы указали неверный e-mail');
		}

		# Проверка на код авторизации
		if (empty($code)) {
			throw new Exception('Ошибка передачи данных');
		}
			
		# Запрос к БД	
		$sth = $dbh->prepare('SELECT id, reg_code FROM users WHERE email=? AND reg_code=? LIMIT 1;');
		$sth->execute(array($email, $code));

		# Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Сверяем данные
		if ($result[0]['reg_code'] == $code) {
			$stmt = $dbh->prepare('UPDATE users SET block=?, reg_code=? WHERE email=? AND reg_code=? AND id=?;');
			$stmt->execute(array(0, NULL, $email, $code, $result[0]['id']));			
		} else {
			throw new Exception('Вы указали неверный код!');
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
# Запрос на создание кода сброса пароля аккаунта
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'create_reset_code':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$email = $_POST['email'];

		# Проверка на сообщение
		if (empty($email)) {
			throw new Exception('Ошибка передачи данных');
		}

		# Проверяем на возможность существования пользователя в этом диалоге
		$sth = $dbh->prepare('SELECT email FROM users AS U WHERE U.email = ?;');
		$sth->execute(array($email));

		# Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			
			# Запрос к БД
			$reset_code = gen_code();
			$stmt = $dbh->prepare('UPDATE users SET reset_code=:reset_code WHERE email=?;');
			$stmt->execute(array($reset_code, $email));
			
			# Отправляем письмо пользователю
			$server_email = server_parameters['mail'];
			$server_name = server_parameters['name'];
			$subject = "$server_name - код сброса пароля"; 
			$message = ' 
			<html> 
				<head> 
					<title>Код сброса пароля</title>				
				</head> 
				<body> 
					<p style="font-size: 13px!Important;">
						<b>Код сброса пароля - $server_name</b> <br>
						<span style="font-size: 13px!Important; color: #777;">Если Вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.</span>
					</p>
					<p style="font-size: 13px!Important;border-color:#e6e6e6;border-width:1px;border-style:solid;background-color:#f5f5f5;padding-top:15px;padding-right:15px;padding-bottom:15px;padding-left:15px;">'.$reset_code.'</p>
				</body> 
			</html>'; 
			$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <$server_email>\r\n";
			mail($email, $subject, $message, $headers); 
			
		} else {
			throw new Exception('Пользователь не найден');
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
# Сбрасываем и создаем временный пароль пользователю
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'reset_password':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$code = $_POST['code'];
		$email = $_POST['email'];
		
		# Проверка на сообщение
		if (empty($code)) {
			throw new Exception('Ошибка передачи данных');
		}

		# Проверка на почту
		if (empty($email)) {
			throw new Exception('Ошибка передачи данных');
		}

		# Запрос к БД
		$sth = $dbh->prepare('SELECT email, reset_code FROM users WHERE email=? AND reset_code=?;');
		$sth->execute(array($email, $code));

		# Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			
			# Запрос к БД
			$pass = gen_code();
			$salt = microtime();
            $hash_pass = hash('sha256', $pass . $salt);
			$stmt = $dbh->prepare('UPDATE users SET password=?, salt=?, reset_code=? WHERE email=? AND reset_code=?;');
			$stmt->execute(array($hash_pass, $salt, NULL, $email, $code));
			
			# Отправляем письмо пользователю
			$server_email = server_parameters['mail'];
			$server_name = server_parameters['name'];
			$subject = "Simple Chat - временный пароль"; 
			$message = ' 
			<html> 
				<head> 
					<title>Временный пароль</title>				
				</head> 
				<body> 
					<p style="font-size: 13px!Important;">
						<b>Временный пароль - Simple Chat</b> <br>
						<span style="font-size: 13px!Important; color: #777;">Не забудьте поменять свой пароль в профиле.</span>
					</p>
					
					<p style="font-size: 13px!Important;border-color:#e6e6e6;border-width:1px;border-style:solid;background-color:#f5f5f5;padding-top:15px;padding-right:15px;padding-bottom:15px;padding-left:15px;">'.$pass.'</p>
				</body> 
			</html>'; 
			$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <$server_email>\r\n";
			mail($email, $subject, $message, $headers); 
			
		} else {
			throw new Exception('Пользователь не найден или сброс пароля не запрашивался');
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
# Профиль пользователя
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'profile_user':


	# Проверяем данные на соответствие
	try {
			
		# Запрос к БД
		$sth = $dbh->prepare("SELECT u.id,
								   (CASE WHEN u.display_name = 0 THEN (u.first_name || ' ' || u.last_name) ELSE u.nickname END) AS show_name,
								   u.email,
								   u.first_name,
								   u.last_name,
								   u.display_name,
								   u.write_me,
								   u.nickname,
								   u.gender,
								   u.avatar,
								   (
									   SELECT COUNT(user_id) AS followers
										 FROM friends
										WHERE user_id = u.id
								   ) AS followers,
								   (
									   SELECT COUNT(user_id) AS moderators
										 FROM channels
										WHERE user_id = u.id AND 
											  type = 3
								   ) AS moderators
							  FROM users AS u
							 WHERE u.id = ? AND 
								   u.session_id = ?;");
		$sth->execute(array($user_id, $session_id));

		# Обработка полученных данных
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		if (count($exist) != 1) {
			throw new Exception('Ошибка обработки данных');
		}
		
		# Выводим сообщение
		$message = ['status' => 'success', 'data' => $exist];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Сохранение профиля
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'profile_save':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$first_name = $_POST['first_name'];
		$last_name = $_POST['last_name'];
		$email = $_POST['email'];
		$nickname = $_POST['nickname'];
		$password = $_POST['password'];
		$display_name = (int) $_POST['display_name'];
		$write_me = (int) $_POST['write_me'];
		
		# Проверка строки на фхождения
		$preg = "/^[a-zа-яё]{1}[a-zа-яё]*[a-zа-яё]{1}$/iu";
		$preg_alnum = "/^[a-zа-яё\d]{1}[a-zа-яё\d]*[a-zа-яё\d]{1}$/iu";
		
		# Проверка на Имя
		if (empty($first_name) or !preg_match($preg, $first_name)) {
			throw new Exception('Вы не указали имя');
		} elseif (mb_strlen($first_name, 'utf-8') < 2) {
			throw new Exception('Имя не может быть менее 2 символов');
		}
		
		# Проверка на Фамилию
		if (empty($last_name) or !preg_match($preg, $last_name)) {
			throw new Exception('Вы не указали фамилию');
		} elseif (mb_strlen($last_name, 'utf-8') < 2) {
			throw new Exception('Фамилия не может быть менее 2 символов');
		}

		# Проверка на почту
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Вы указали неверный e-mail');
		}
		
		# Проверяем значения
		if (!empty($password)) {
			$salt = microtime();
            $hash_pass = hash('sha256', $password . $salt);
			$update_session_id = gen_id();
			
			# Запрос к БД
			$stmt = $dbh->prepare('UPDATE users SET password=?, salt=?, session_id=? WHERE id=? AND session_id=?;');
			$stmt->execute(array($hash_pass, $salt, $update_session_id, $user_id, $session_id));
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE users SET first_name=?, last_name=?, email=?, nickname=?, display_name=?, write_me=? WHERE id=? AND session_id=?;');
		$stmt->execute(array($first_name, $last_name, $email, $nickname, $display_name, $write_me, $user_id, $session_id));
		
		# Выводим сообщение
		$message = ['status' => 'success', 'update_session_id' => $update_session_id];
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

		# Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
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
			$sth = $dbh->prepare('SELECT avatar FROM users WHERE id=?');
			$sth->execute(array($user_id));
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
			$stmt = $dbh->prepare('UPDATE users SET avatar=? WHERE id=?;');
			$stmt->execute(array($avatar_id, $user_id));
			
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
# Загрузка списка пользователей
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'users_list':


	# Проверяем данные на соответствие
	try {
		
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
											  friend_id = u.id
								   )
								   AS friend,
								   u.avatar,
								   u.gender
							  FROM users AS u
							 WHERE u.block = 0;");
		$sth->execute(array($user_id));

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
# Загрузка списка друзей
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'friends_list':


	# Проверяем данные на соответствие
	try {
		
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
										WHERE user_id = f.user_id AND 
											  friend_id = u.id
								   )
								   AS friend,
								   u.avatar,
								   u.gender
							  FROM friends AS f
								   JOIN
								   users AS u ON u.id = f.friend_id
							 WHERE f.user_id = ?;");
		$sth->execute(array($user_id));

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
# Поиск пользователей
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'search_users':


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
											  friend_id = u.id
								   )
								   AS friend,
								   u.avatar,
								   u.gender
							  FROM users AS u
							 WHERE u.block = 0 AND 
								   (u.first_name LIKE ?) OR 
								   (u.last_name LIKE ?) OR 
								   (u.nickname LIKE ?);");
		$sth->execute(array($user_id, $str, $str, $str));

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
# Добавить в друзья
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'friend_add':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$friend_id = (int) $_POST['friend_id'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($friend_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if ($user_id == $friend_id) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Загружаем список
		$sth = $dbh->prepare("SELECT user_id, friend_id FROM friends WHERE user_id=? AND friend_id=?;");
		$sth->execute(array($user_id, $friend_id));

		# Обрабатываем
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($exist) > 0) {
			throw new Exception('Уже у Вас в друзьях');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO friends (user_id, friend_id, date_create) VALUES (?, ?, ?);');
		$stmt->execute(array($user_id, $friend_id, $date_create));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);
		
	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удалить из друзей
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'friend_remove':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$friend_id = (int) $_POST['friend_id'];
		
		# Проверяем значения
		if (empty($friend_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM friends WHERE user_id=? AND friend_id=?;');
		$stmt->execute(array($user_id, $friend_id));
		
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