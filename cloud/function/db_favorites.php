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
# Получаем папки
#---------------------------------------------------------------------------------------------------------------------------------------------------------------

	
switch ($action) {
case 'folder_list':


	# Проверяем данные на соответствие
	try {
		
		# Подготавливаем запрос в базу данных
		$sth = $dbh->prepare("SELECT * FROM favorites WHERE user_id = ?;");
		$sth->execute(array($user_id));	
		
		# Формируем массив разделов, ключом будет id родительской категории
		$favorites = $sth->fetchAll(PDO::FETCH_ASSOC);

		# В Цикле формируем многомерный массив - дерево каталогов
		function buildTreeArray($arItems, $p_id = 'p_id', $id = 'id') {
			$childs = array();
			if(!is_array($arItems) || empty($arItems)) {
				return array();
			}
			foreach($arItems as &$item) {
				if(!$item[$p_id]) {
					$item[$p_id] = 0;
				}
				$childs[$item[$p_id]][] = &$item;
			}
			unset($item);
			foreach($arItems as &$item) {
				if (isset($childs[$item[$id]])) {
					$item['childs'] = $childs[$item[$id]];
				}
			}
			return $childs[0];
		}
		
		# Выводим сообщение
		$message = ['status' => 'success',
					'data' => buildTreeArray($favorites)
				   ];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Создание категории
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'create_folder':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$folder_id = (int) $_POST['folder_id'];
		$name = $_POST['name'];
		$date = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($folder_id)) {
			$folder_id = null;
		}
		
		# Проверяем значения
		if (empty($name)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO favorites (p_id, name, user_id, date_create) VALUES (?, ?, ?, ?)');
		$stmt->execute(array($folder_id, $name, $user_id, $date));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удаление категории
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'remove_folder':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$folder_id = (int) $_POST['folder_id'];
		
		# Проверяем значения
		if (empty($folder_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM favorites WHERE id=? and user_id=?;');
		$stmt->execute(array($folder_id, $user_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Замена имени у папки
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'rename_folder':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$folder_id = (int) $_POST['folder_id'];
		$name = $_POST['name'];
		
		# Проверяем значения
		if (empty($folder_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Проверяем значения
		if (empty($name)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE favorites SET name=? WHERE id=?');
		$stmt->execute(array($name, $folder_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Функция снятия или установки избранного
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'favorite_post':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$folder_id = (int) $_POST['folder_id'];
		$date = date("Y-m-d H:i:s");
		
		# Проверка на id сообщения
		if (empty($post_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Проверка на id категории
		if (empty($folder_id)) {
			$folder_id = null;
		}
		
		# Проверка на существование записи
		$sth = $dbh->prepare("SELECT id FROM favorite AS F WHERE F.post_id = ? AND user_id = ?;");
		$sth->execute(array($post_id, $user_id));		
		$is = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Если запись не установлена то записываем
		if (count($is) == 0) {
			
			# ---------------------------------------------------------------------------------------
		
			# Запрос к БД для проверки безопасности
			$sth = $dbh->prepare("SELECT f.id
									  FROM followers AS f
									  JOIN posts as p ON p.channel_id = f.channel_id
									 WHERE p.id = ? AND 
										   f.user_id = ?;");
			$sth->execute(array($post_id, $user_id));

			# Обрабатываем данные
			$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# Проверки безопасности
			if (count($exist) == 0) {
				throw new Exception('У Вас нет доступа');
			}
			
			# ---------------------------------------------------------------------------------------
			
			$stmt = $dbh->prepare('INSERT INTO favorite (post_id, f_id, user_id, date_create) VALUES (?, ?, ?, ?)');
			$stmt->execute(array($post_id, $folder_id, $user_id, $date));			
		} else {
			$stmt = $dbh->prepare('DELETE FROM favorite WHERE post_id=? AND user_id = ?;');
			$stmt->execute(array($post_id, $user_id));
		}
		
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